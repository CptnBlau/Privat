# DocScan Mail PWA v2

## Dateien
Diese Dateien müssen direkt im Root des GitHub-Repositories liegen:

- index.html
- manifest.webmanifest
- service-worker.js
- icons/icon-192.svg
- icons/icon-512.svg

## GitHub Pages
Settings > Pages > Deploy from branch > main > /root

Danach:
https://cptnblau.github.io/Privat/index.html

## Hinweis zu Mail
Eine PWA kann aus Sicherheitsgründen nicht zuverlässig eine Mailadresse vorausfüllen UND gleichzeitig eine Datei als Anhang an Gmail/Outlook übergeben.
Deshalb nutzt die App die native Teilen-Funktion. Die PDF wird als Datei an die Mail-App übergeben.
