# DocScan Mail PWA

Serverlose PWA zum Scannen von Dokumenten, Erzeugen einer PDF und Teilen an eine Mail-App.

## Start lokal
Die App muss über HTTPS oder localhost laufen, sonst blockiert der Browser die Kamera.

Einfacher Test:
```bash
python -m http.server 8080
```
Dann im Browser öffnen:
http://localhost:8080

## Kostenlos erreichbar machen
Option: GitHub Pages

1. Neues GitHub Repository erstellen, z. B. `docscan-mail`
2. Dateien aus diesem ZIP hochladen
3. In GitHub: Settings > Pages > Deploy from branch > main/root
4. Die angezeigte HTTPS-URL auf dem Smartphone öffnen
5. Im Browser "Zum Startbildschirm hinzufügen"

## Wichtig
Ein direkter Mail-Anhang über `mailto:` funktioniert browserseitig nicht zuverlässig.
Diese App nutzt deshalb die Web Share API. Auf Smartphones kannst du dann Gmail, Outlook oder Apple Mail auswählen.
Wenn der Browser Dateien nicht teilen kann, lädt die App die PDF herunter und öffnet eine Mail ohne Anhang als Fallback.
