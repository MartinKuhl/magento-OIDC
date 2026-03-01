<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;

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

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var State */
    private readonly State $appState;

    /**
     * Initialize import command.
     *
     * @param OAuthUtility                     $oauthUtility
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     * @param EncryptorInterface               $encryptor
     * @param State                            $appState
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        EncryptorInterface $encryptor,
        State $appState
    ) {
        $this->oauthUtility      = $oauthUtility;
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        $this->encryptor         = $encryptor;
        $this->appState          = $appState;
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

            // Apply data (exclude internal primary key from the import payload)
            $importData = $provider;
            unset($importData['id']); // never import primary key

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
            $output->writeln("<info>  [{$label}] {$action}" . ($dryRun ? ' (dry run)' : '') . '</info>');

            if (!$dryRun) {
                try {
                    $model->setData($importData);
                    $this->appResource->save($model);
                    if ($existing !== null) {
                        $updated++;
                    } else {
                        $inserted++;
                    }
                } catch (\Exception $e) {
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

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
