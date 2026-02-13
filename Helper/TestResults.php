<?php
namespace MiniOrange\OAuth\Helper;

class TestResults
{
    public function output($exception = null, $hasException = false, $data = [])
    {
        $html = '';
        if ($hasException && $exception) {
            $html .= "<div class='error'>" . $exception->getMessage() . "</div>";
        } else {
            $html .= "<div class='success'>Test successful!</div>";
            $html .= "<div><strong>Mail:</strong> " . htmlspecialchars($data['mail'] ?? '') . "</div>";
            $html .= "<div><strong>Userinfo:</strong> <pre>" . htmlspecialchars(print_r($data['userinfo'] ?? [], true)) . "</pre></div>";
            // Debugging: Gibt alle empfangenen Parameter aus
            if (!empty($data['debug'])) {
                $html .= "<div style='background:#fafafa; border:1px solid #ccc;padding:10px;'><strong>Full Params (Debug):</strong><pre>"
                    . htmlspecialchars(print_r($data['debug'], true))
                    . "</pre></div>";
            }
        }
        return $html;
    }
}
