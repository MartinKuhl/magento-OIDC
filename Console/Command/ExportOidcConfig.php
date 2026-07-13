<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping as RoleMappingResource;

/**
 * CLI command: export one or all OIDC provider configurations to JSON (FEAT-07).
 *
 * Usage:
 *   bin/magento oidc:config:export [--provider-id=<id>] [--output=<file>]
 *
 * Output format (NDJSON-safe single JSON object):
 * {
 *   "exported_at": "2026-02-27T10:00:00+00:00",
 *   "module_version": "4.2.0",
 *   "providers": [ { ...provider fields... } ]
 * }
 *
 * Sensitive fields (client_secret) are Magento-encrypted in the export.
 * The `--no-encrypt` flag can be used for testing — NOT for production.
 */
class ExportOidcConfig extends Command
{
    /** Fields that contain sensitive credentials. */
    private const SENSITIVE_FIELDS = ['client_secret', 'health_alert_webhook_url'];

    /** Fields excluded entirely from the export (runtime / internal metadata, not secrets). */
    private const EXCLUDED_FIELDS = [
        'received_oidc_claims', 'last_test_status', 'last_test_at',
        'health_alert_consecutive_failures', 'health_alert_last_status',
        'health_alert_first_failure_at', 'health_alert_last_notified_at',
    ];

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var State */
    private readonly State $appState;

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /**
     * Initialize export command.
     *
     * @param OAuthUtility       $oauthUtility
     * @param EncryptorInterface $encryptor
     * @param State              $appState
     * @param MappingRepository  $mappingRepository
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        EncryptorInterface $encryptor,
        State $appState,
        MappingRepository $mappingRepository
    ) {
        $this->oauthUtility      = $oauthUtility;
        $this->encryptor         = $encryptor;
        $this->appState          = $appState;
        $this->mappingRepository = $mappingRepository;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('oidc:config:export')
            ->setDescription('Export OIDC provider configuration(s) to a JSON file.')
            ->addOption(
                'provider-id',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Export only the provider with this numeric ID (default: all providers).'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Path to the output JSON file (default: stdout).'
            )
            ->addOption(
                'no-encrypt',
                null,
                InputOption::VALUE_NONE,
                'Store client_secret in plain text. Use only in non-production environments.'
            );
    }

    /**
     * Execute the export command.
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
            // Area already set — safe to continue
        }
        // phpcs:enable Magento2.CodeAnalysis.EmptyBlock.DetectedCatch

        $providerId = $input->getOption('provider-id');
        $outputFile = $input->getOption('output');
        $noEncrypt  = (bool) $input->getOption('no-encrypt');

        $this->oauthUtility->customlog(
            "Export started"
            . ($providerId !== null ? " for provider ID {$providerId}" : " for all providers")
            . ($noEncrypt ? " with --no-encrypt (PLAIN TEXT!)" : "")
        );

        // Load providers
        if ($providerId !== null) {
            $pid      = (int) $providerId;
            $provider = $this->oauthUtility->getClientDetailsById($pid);
            if ($provider === null) {
                $this->oauthUtility->customlog("Export ERROR: Provider ID {$pid} not found");
                $output->writeln("<error>Provider ID {$pid} not found.</error>");
                return Command::FAILURE;
            }
            $providers = [$provider];
        } else {
            $providers = $this->oauthUtility->getAllActiveProviders('both');
            // Also include inactive providers in a full export
            $collection = $this->oauthUtility->getOAuthClientApps();
            $allIds     = [];
            foreach ($collection as $item) {
                $allIds[] = (int) $item->getId();
            }
            $exportedIds = array_column($providers, 'id');
            foreach (array_diff($allIds, $exportedIds) as $missingId) {
                $row = $this->oauthUtility->getClientDetailsById($missingId);
                if ($row !== null) {
                    $providers[] = $row;
                }
            }
        }

        // Prepare payload
        $exportData = [
            'exported_at'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'module_version' => OAuthConstants::VERSION,
            'providers'      => [],
        ];

        foreach ($providers as $provider) {
            $row = $this->sanitizeProviderForExport($provider, $noEncrypt);
            $pid = (int) ($provider['id'] ?? 0);
            $appName = $provider['app_name'] ?? 'unknown';
            $this->oauthUtility->customlog("Export: provider '{$appName}' (ID: {$pid})");
            if ($pid > 0) {
                $row['attribute_mappings'] = $this->mappingRepository->getFullAttributeMap($pid);
                $row['role_mappings']      = [
                    RoleMappingResource::TYPE_ADMIN_ROLE     =>
                        $this->mappingRepository->getAdminRoleMappings($pid),
                    RoleMappingResource::TYPE_CUSTOMER_GROUP =>
                        $this->mappingRepository->getCustomerGroupMappings($pid),
                ];
            }
            $exportData['providers'][] = $row;
        }

        $json = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $output->writeln('<error>Failed to encode provider data as JSON.</error>');
            return Command::FAILURE;
        }

        if ($outputFile !== null && $outputFile !== '') {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            if (file_put_contents($outputFile, $json) === false) {
                $this->oauthUtility->customlog("Export ERROR: Could not write to file '{$outputFile}'");
                $output->writeln("<error>Could not write to file: {$outputFile}</error>");
                return Command::FAILURE;
            }
            $count = count($exportData['providers']);
            $this->oauthUtility->customlog("Export complete: {$count} provider(s) written to '{$outputFile}'");
            $output->writeln(
                sprintf(
                    '<info>Exported %d provider(s) to %s</info>',
                    $count,
                    $outputFile
                )
            );
        } else {
            $count = count($exportData['providers']);
            $this->oauthUtility->customlog("Export complete: {$count} provider(s) sent to stdout");
            $output->write($json);
        }

        return Command::SUCCESS;
    }

    /**
     * Prepare a provider row for export.
     *
     * Sensitive fields are re-encrypted using Magento's encryptor so that the
     * value can be safely stored in version control or passed between environments.
     * Use --no-encrypt only in non-production scenarios.
     *
     * @param  mixed[] $provider   Raw provider data from the database
     * @param  bool    $noEncrypt  When true, sensitive fields are stored in plain text
     * @return array<string, mixed> Export-ready array
     */
    private function sanitizeProviderForExport(array $provider, bool $noEncrypt): array
    {
        $row = array_diff_key($provider, array_flip(self::EXCLUDED_FIELDS));

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === '') {
                continue;
            }

            if ($noEncrypt) {
                // Keep plain text — caller accepted the risk
                continue;
            }

            $plain = (string) $row[$field];
            // Re-encrypt: if value is already an encrypted Magento token, decrypt first
            if (preg_match('/^\d+:\d+:/', $plain)) {
                $plain = $this->encryptor->decrypt($plain);
            }
            $row[$field] = $this->encryptor->encrypt($plain);
        }

        return $row;
    }
}
