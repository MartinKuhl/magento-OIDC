<?php
namespace MiniOrange\OAuth\Helper;

class TestResults
{
    public function output($exception = null, $hasException = false, $data = [])
    {
        // Erzeuge hier das HTML für Testergebnisse,
        // Fehlerausgabe etc. Nutze $exception/$hasException für Fehlerfall.
        if ($hasException && $exception) {
            return "<div class='error'>".$exception->getMessage()."</div>";
        }
        // Sonst: Erfolgsausgabe
        return "<div class='success'>Test erfolgreich! Daten: ".htmlspecialchars(json_encode($data))."</div>";
    }
}
