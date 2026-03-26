<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\Framework\Escaper;

class TestResults
{
    /** @var Escaper */
    private readonly Escaper $escaper;

    /**
     * Data class for holding OAuth test result attributes.
     *
     * @param Escaper $escaper
     */
    public function __construct(Escaper $escaper)
    {
        $this->escaper = $escaper;
    }

    /**
     * Render test result HTML output.
     *
     * @param \Exception|null $exception    Exception to display on failure
     * @param bool            $hasException Whether an exception occurred
     * @param mixed[]         $data         Test result data to render
     */
    public function output(\Exception|null $exception = null, bool $hasException = false, array $data = []): string
    {
        $html = '';
        if ($hasException && $exception) {
            $errOut = $this->escaper->escapeHtml($exception->getMessage());
            $html .= "<div class='error'>" . (is_array($errOut) ? implode('', $errOut) : $errOut) . "</div>";
        } else {
            $html .= "<div class='success'>Test successful!</div>";
            $mail = $data['mail'] ?? '';
            $userinfo = $data['userinfo'] ?? [];
            $debug = $data['debug'] ?? null;

            $mailEscaped = $this->escaper->escapeHtml(is_array($mail) ? implode('', $mail) : (string) $mail);
            $mailOut = is_array($mailEscaped) ? implode('', $mailEscaped) : (string) $mailEscaped;
            $userinfoEscaped = $this->escaper->escapeHtml(var_export($userinfo, true));
            $userinfoOut = is_array($userinfoEscaped) ? implode('', $userinfoEscaped) : (string) $userinfoEscaped;

            $html .= "<div><strong>Mail:</strong> " . $mailOut . "</div>";
            $html .= "<div><strong>Userinfo:</strong> <pre>" . $userinfoOut . "</pre></div>";

            // Debugging: Ausgabe aller empfangenen Parameter
            if (!empty($debug)) {
                $debugEscaped = $this->escaper->escapeHtml(var_export($debug, true));
                $debugOut = is_array($debugEscaped) ? implode('', $debugEscaped) : (string) $debugEscaped;
                $html .= "<div style='background:#fafafa; border:1px solid #ccc;padding:10px;'>"
                    . "<strong>Full Params (Debug):</strong><pre>" . $debugOut . "</pre></div>";
            }
        }
        return $html;
    }
}
