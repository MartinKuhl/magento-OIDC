<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;
use M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping as RoleMappingResource;
use M2Oidc\OAuth\Model\Validation\ProviderDataValidator;

/**
 * CLI command: import OIDC provider configurations from a JSON file (FEAT-07).
 *
 * Usage:
 *   bin/magento oidc:config:import --input=<file> [--dry-run] [--overwrite]
 *
 * Behaviour:
 *  - Providers whose `app_name` already exists in the DB are SKIPPED by default.
 *  - With --overwrite, existing providers are updated (UPDATE by app_name).
 *  - --dry-run validates the file and shows what would happen without persisting.
 *  - client_secret values in Magento encryption format (0:N:...) are stored as-is.
 *    Plain-text values are encrypted on import.
 */
class ImportOidcConfig extends Command
{
    /** Fields that must be present in every provider record. */
    private const REQUIRED_FIELDS = ['app_name'];

    /** Fields that are re-encrypted on import. */
    private const SENSITIVE_FIELDS = ['client_secret'];

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var M2oidcOauthClientAppsFactory */
    private readonly M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var State */
    private readonly State $appState;

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /** @var ProviderDataValidator */
    private readonly ProviderDataValidator $providerDataValidator;

    /**
     * Initialize import command.
     *
     * @param OAuthUtility                 $oauthUtility
     * @param M2oidcOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                  $appResource
     * @param EncryptorInterface           $encryptor
     * @param State                        $appState
     * @param MappingRepository            $mappingRepository
     * @param ProviderDataValidator        $providerDataValidator
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        EncryptorInterface $encryptor,
        State $appState,
        MappingRepository $mappingRepository,
        ProviderDataValidator $providerDataValidator
    ) {
        $this->oauthUtility          = $oauthUtility;
        $this->clientAppsFactory     = $clientAppsFactory;
        $this->appResource           = $appResource;
        $this->encryptor             = $encryptor;
        $this->appState              = $appState;
        $this->mappingRepository     = $mappingRepository;
        $this->providerDataValidator = $providerDataValidator;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('oidc:config:import')
            ->setDescription('Import OIDC provider configuration(s) from a JSON file.')
            ->addOption(
                'input',
                'i',
                InputOption::VALUE_REQUIRED,
                'Path to the JSON export file produced by oidc:config:export.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Validate the file and show what would be imported without persisting.'
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Update existing providers (matched by app_name) instead of skipping.'
            );
    }

    /**
     * Execute the import command.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int 0 on success, 1 on error
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // phpcs:disable Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            // Area already set
        }
        // phpcs:enable Magento2.CodeAnalysis.EmptyBlock.DetectedCatch

        $inputFile = $input->getOption('input');
        $dryRun    = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');

        if (!$inputFile) {
            $output->writeln('<error>--input option is required.</error>');
            return Command::FAILURE;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        if (!is_file($inputFile) || !is_readable($inputFile)) {
            $output->writeln("<error>File not found or not readable: {$inputFile}</error>");
            return Command::FAILURE;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $json = file_get_contents($inputFile);
        if ($json === false || $json === '') {
            $output->writeln('<error>Failed to read the input file.</error>');
            return Command::FAILURE;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['providers']) || !is_array($data['providers'])) {
            $output->writeln('<error>Invalid export file format. Expected {"providers": [...]}.</error>');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] No changes will be persisted.</comment>');
        }

        $this->oauthUtility->customlog(
            "Import started"
            . ($dryRun ? " (DRY RUN)" : "")
            . " from '{$inputFile}'"
            . ($overwrite ? " with --overwrite" : "")
        );

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($data['providers'] as $index => $provider) {
            $label = $provider['app_name'] ?? "(index {$index})";

            // Validate required fields
            $missing = [];
            foreach (self::REQUIRED_FIELDS as $field) {
                if (empty($provider[$field])) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                $output->writeln(
                    "<error>  [{$label}] Missing required fields: " . implode(', ', $missing) . '</error>'
                );
                $errors++;
                continue;
            }

            // Check for existing provider by app_name
            $existing = $this->oauthUtility->getClientDetailsByAppName((string) $provider['app_name']);

            if ($existing !== null && !$overwrite) {
                $this->oauthUtility->customlog("Import: SKIP provider '{$label}' — already exists");
                $output->writeln("<comment>  [{$label}] SKIP — already exists (use --overwrite to update).</comment>");
                $skipped++;
                continue;
            }

            // Prepare model
            $model = $this->clientAppsFactory->create();

            if ($existing !== null) {
                // Load for UPDATE
                $this->appResource->load($model, $existing['id']);
            }

            // Separate normalized mapping data from provider core data
            $attributeMappings = $provider['attribute_mappings'] ?? [];
            $roleMappings      = $provider['role_mappings'] ?? [];

            // Apply data (exclude internal primary key and normalized tables from provider row)
            $importData = $provider;
            unset($importData['id'], $importData['attribute_mappings'], $importData['role_mappings']);

            // Handle sensitive field encryption
            foreach (self::SENSITIVE_FIELDS as $field) {
                if (!array_key_exists($field, $importData) || $importData[$field] === '') {
                    continue;
                }
                $value = (string) $importData[$field];
                // Decrypt Magento-encrypted values to get plain text, then re-encrypt
                // with this environment's key to ensure portability.
                if (preg_match('/^\d+:\d+:/', $value)) {
                    try {
                        $value = $this->encryptor->decrypt($value);
                    } catch (\Exception $e) {
                        $output->writeln(
                            "<comment>  [{$label}] Could not decrypt {$field} — storing as-is.</comment>"
                        );
                    }
                }
                $importData[$field] = $this->encryptor->encrypt($value);
            }

            $action = ($existing !== null) ? 'UPDATE' : 'INSERT';
            if ($action === 'INSERT') {
                $this->oauthUtility->customlog("Import: INSERT provider '{$label}'");
            } elseif ($action === 'UPDATE') {
                $this->oauthUtility->customlog("Import: UPDATE provider '{$label}'");
            }
            $output->writeln("<info>  [{$label}] {$action}" . ($dryRun ? ' (dry run)' : '') . '</info>');

            if (!$dryRun) {
                try {
                    $this->persistProvider(
                        $model,
                        $importData,
                        $attributeMappings,
                        $roleMappings,
                        $output,
                        (string) $label
                    );
                    if ($existing !== null) {
                        $updated++;
                    } else {
                        $inserted++;
                    }
                } catch (\Exception $e) {
                    $this->oauthUtility->customlog("Import ERROR for '{$label}': " . $e->getMessage());
                    $output->writeln("<error>  [{$label}] Save failed: " . $e->getMessage() . '</error>');
                    $errors++;
                }
            } else {
                if ($existing !== null) {
                    $updated++;
                } else {
                    $inserted++;
                }
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Import complete%s: %d inserted, %d updated, %d skipped, %d error(s).</info>',
            $dryRun ? ' (dry run)' : '',
            $inserted,
            $updated,
            $skipped,
            $errors
        ));

        $this->oauthUtility->customlog(
            "Import complete: {$inserted} inserted, {$updated} updated, {$skipped} skipped, {$errors} error(s)"
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Save provider model and restore its normalized mapping tables.
     *
     * Extracted from execute() to keep nesting level within coding-standard limits.
     *
     * @param \M2Oidc\OAuth\Model\M2oidcOauthClientApps $model
     * @param mixed[]                                   $importData         Provider import data
     * @param mixed[]                                   $attributeMappings  Attribute mappings
     * @param mixed[]                                   $roleMappings       Role mappings
     * @param OutputInterface                           $output             CLI output for warnings
     * @param string                                    $label              Provider label for messages
     */
    private function persistProvider(
        \M2Oidc\OAuth\Model\M2oidcOauthClientApps $model,
        array $importData,
        array $attributeMappings,
        array $roleMappings,
        OutputInterface $output,
        string $label
    ): void {
        // C-03: shared whitelist / SSRF / lockout validation before persisting
        $validation = $this->providerDataValidator->validate($importData, (int) $model->getId());
        foreach ($validation->getWarnings() as $warning) {
            $output->writeln("<comment>  [{$label}] WARNING: {$warning}</comment>");
        }
        if (!$validation->isValid()) {
            throw new \RuntimeException(
                'Validation failed: ' . implode(' ', $validation->getErrors())
            );
        }
        $importData = $validation->getData();

        $model->setData($importData);
        $this->appResource->save($model);
        $savedId = (int) $model->getId();

        if ($savedId > 0 && $attributeMappings !== []) {
            foreach ($attributeMappings as $type => $attrData) {
                $name = is_array($attrData)
                    ? (string) ($attrData['attribute_name'] ?? '')
                    : (string) $attrData;
                $syncOnSso = is_array($attrData)
                    ? (int) ($attrData['sync_on_sso'] ?? 0)
                    : 0;
                if ($name !== '') {
                    $this->mappingRepository->saveAttributeMapping(
                        $savedId,
                        (string) $type,
                        $name,
                        $syncOnSso
                    );
                }
            }
        }

        if ($savedId > 0 && $roleMappings !== []) {
            $mappingTypes = [
                RoleMappingResource::TYPE_ADMIN_ROLE,
                RoleMappingResource::TYPE_CUSTOMER_GROUP,
            ];
            foreach ($mappingTypes as $mappingType) {
                if (!empty($roleMappings[$mappingType])
                    && is_array($roleMappings[$mappingType])
                ) {
                    $this->mappingRepository->replaceRoleMappings(
                        $savedId,
                        $mappingType,
                        $roleMappings[$mappingType]
                    );
                }
            }
        }
    }
}
