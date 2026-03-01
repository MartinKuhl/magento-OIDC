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
    private const SENSITIVE_FIELDS = ['client_secret'];

    /** Fields excluded entirely from the export (runtime / internal). */
    private const EXCLUDED_FIELDS = [];

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var State */
    private readonly State $appState;

    /**
     * Initialize export command.
     *
     * @param OAuthUtility       $oauthUtility
     * @param EncryptorInterface $encryptor
     * @param State              $appState
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        EncryptorInterface $encryptor,
        State $appState
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->encryptor    = $encryptor;
        $this->appState     = $appState;
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

        // Load providers
        if ($providerId !== null) {
            $pid      = (int) $providerId;
            $provider = $this->oauthUtility->getClientDetailsById($pid);
            if ($provider === null) {
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
            'module_version' => '4.2.0',
            'providers'      => [],
        ];

        foreach ($providers as $provider) {
            $row = $this->sanitizeProviderForExport($provider, $noEncrypt);
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
                $output->writeln("<error>Could not write to file: {$outputFile}</error>");
                return Command::FAILURE;
            }
            $output->writeln(
                sprintf(
                    '<info>Exported %d provider(s) to %s</info>',
                    count($exportData['providers']),
                    $outputFile
                )
            );
        } else {
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
     * @param  array $provider    Raw provider data from the database
     * @param  bool  $noEncrypt   When true, sensitive fields are stored in plain text
     * @return array Export-ready array
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
