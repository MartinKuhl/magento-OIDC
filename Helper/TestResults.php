<?php
namespace MiniOrange\OAuth\Helper;

use Magento\Framework\Escaper;

class TestResults
{
    /** @var Escaper|null */
    private $escaper;

    public function __construct(Escaper $escaper = null)
    {
        $this->escaper = $escaper;
    }

    public function output($exception = null, $hasException = false, $data = [])
    {
        $html = '';
        if ($hasException && $exception) {
            $html .= "<div class='error'>" . ($this->escaper ? $this->escaper->escapeHtml($exception->getMessage()) : htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')) . "</div>";
        } else {
            $html .= "<div class='success'>Test successful!</div>";
            $mail = $data['mail'] ?? '';
            $userinfo = $data['userinfo'] ?? [];
            $debug = $data['debug'] ?? null;

            $mailOut = $this->escaper ? $this->escaper->escapeHtml($mail) : htmlspecialchars($mail, ENT_QUOTES, 'UTF-8');
            $userinfoOut = $this->escaper ? $this->escaper->escapeHtml(var_export($userinfo, true)) : htmlspecialchars(var_export($userinfo, true), ENT_QUOTES, 'UTF-8');

            $html .= "<div><strong>Mail:</strong> " . $mailOut . "</div>";
            $html .= "<div><strong>Userinfo:</strong> <pre>" . $userinfoOut . "</pre></div>";

            // Debugging: Ausgabe aller empfangenen Parameter
            if (!empty($debug)) {
                $debugOut = $this->escaper ? $this->escaper->escapeHtml(var_export($debug, true)) : htmlspecialchars(var_export($debug, true), ENT_QUOTES, 'UTF-8');
                $html .= "<div style='background:#fafafa; border:1px solid #ccc;padding:10px;'><strong>Full Params (Debug):</strong><pre>" . $debugOut . "</pre></div>";
            }
        }
        return $html;
    }
}
