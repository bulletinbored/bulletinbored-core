<?php
/**
 * Plugin Name: hellobored
 * Version: 1.0.0
 * Author: mlzog
 * Description: Plugin example — displays a random quote on every page.
 * License: MIT License
 */

function hellobored_init() {
    global $pluginManager;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/hellobored';

    $quotes = [
        "Ciao, Bored!",
        "Non mollare mai.",
        "Il codice è poesia.",
        "La vita è troppo breve per cattivo codice.",
        "Hello, world!",
        "Semplicità è la massima sofisticazione.",
        "Debugging: essere detective in un film crimine dove sei anche l'assassino.",
        "Talk is cheap. Show me the code.",
    ];

    $quote = $quotes[array_rand($quotes)];

    $html = '<div id="hellobored-quote">' . htmlspecialchars($quote, ENT_QUOTES, 'UTF-8') . '</div>' . "\n";
    $html .= '<link href="' . $pluginUrl . '/assets/css/hellobored.css" rel="stylesheet">' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($html) {
        echo $html;
    });
}
