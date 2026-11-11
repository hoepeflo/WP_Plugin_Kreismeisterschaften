#!/usr/bin/env bash
# Lädt das gebaute Plugin per SFTP in den Plugin-Ordner der Website.
#
# Aufruf:   bash tools/deploy-sftp.sh <lokaler Ordner mit dem fertigen Plugin>
# Umgebung: KMM_SFTP_HOST, KMM_SFTP_USER, KMM_SFTP_PASS (Pflicht)
#           KMM_SFTP_PORT (Standard 22)
#           KMM_ZIEL      Zielordner relativ zum Startordner des SFTP-Benutzers
#                         (Standard "/": der Benutzer startet direkt im Plugin-Ordner)
#
# Hochgeladen wird nur, was sich geändert hat: Auf dem Server liegt die Liste der
# Prüfsummen des letzten Uploads (.kmm-deploy-state). Fehlt sie, geht alles hoch.
# Dateien, die es im Paket nicht mehr gibt, werden gelöscht – aber nur solche, die ein
# früherer Upload dieser Liste nach selbst angelegt hat.
#
# Vor dem Upload wird geprüft, dass im Ziel das installierte Plugin liegt und es kein
# WordPress-Hauptordner ist. Sonst bricht das Skript ab, ohne etwas zu verändern.
set -euo pipefail

LOKAL="${1:?Aufruf: deploy-sftp.sh <lokaler Ordner>}"
: "${KMM_SFTP_HOST:?KMM_SFTP_HOST fehlt}" "${KMM_SFTP_USER:?KMM_SFTP_USER fehlt}" "${KMM_SFTP_PASS:?KMM_SFTP_PASS fehlt}"
PORT="${KMM_SFTP_PORT:-22}"
HAUPTDATEI="ksv-km-meldeportal.php"
STAND=".kmm-deploy-state"

fehler() { echo "::error::$*"; exit 1; }
[ -f "$LOKAL/$HAUPTDATEI" ] || fehler "Im lokalen Ordner „$LOKAL“ fehlt $HAUPTDATEI – das Paket ist unvollständig. Nichts hochgeladen."
LOKAL="$(cd "$LOKAL" && pwd)"

# Zielordner relativ zum Startordner: "/" oder leer heißt „hier“.
ZIEL="${KMM_ZIEL:-/}"
ZIEL="${ZIEL#/}"
ZIEL="${ZIEL%/}"

ARBEIT="$(mktemp -d)"
trap 'rm -rf "$ARBEIT"' EXIT

# lftp mit festen Einstellungen. Das Passwort steht nur in der Umgebung, nie in der Befehlszeile.
# Der Schlüssel des Servers wird beim ersten Kontakt angenommen.
sftp_lauf() {
	LFTP_PASSWORD="$KMM_SFTP_PASS" lftp -c "
		set cmd:fail-exit yes
		set net:max-retries 2
		set net:reconnect-interval-base 3
		set net:timeout 30
		set sftp:auto-confirm yes
		set sftp:connect-program 'ssh -a -x -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=$ARBEIT/known_hosts -o NumberOfPasswordPrompts=1'
		open --env-password -u '$KMM_SFTP_USER' -p $PORT sftp://$KMM_SFTP_HOST
		${ZIEL:+cd '$ZIEL'}
		$1
	"
}

# ----- 1. Anmeldung und Zielordner prüfen --------------------------------------------------

if ! sftp_lauf "cls -1 > $ARBEIT/inhalt.txt" 2> "$ARBEIT/fehler.txt"; then
	meldung="$(tr -d '\r' < "$ARBEIT/fehler.txt" | tail -2 | tr '\n' ' ')"
	if grep -qiE 'login failed|permission denied|authentication|password' "$ARBEIT/fehler.txt"; then
		fehler "SFTP-Anmeldung abgelehnt (${meldung}). Benutzername und Passwort in KMM_FTP_USER und KMM_FTP_PASS prüfen. Nichts hochgeladen."
	fi
	fehler "SFTP-Server nicht erreichbar oder Zielordner „${ZIEL:-/}“ nicht vorhanden (${meldung}). Nichts hochgeladen."
fi
grep -qxF "$HAUPTDATEI" "$ARBEIT/inhalt.txt" || fehler "Angemeldet, aber im Zielordner „${ZIEL:-/}“ liegt keine $HAUPTDATEI. Der SFTP-Benutzer muss in wp-content/plugins/ksv-km-meldeportal/ starten (oder KMM_FTP_SERVER_DIR dorthin zeigen). Nichts hochgeladen."
if grep -qxE 'wp-load\.php|wp-config\.php|wp-admin/?' "$ARBEIT/inhalt.txt"; then
	fehler "Der Zielordner „${ZIEL:-/}“ ist ein WordPress-Hauptordner. Nichts hochgeladen."
fi
echo "Zielordner ist der Plugin-Ordner des KM-Portals."

# ----- 2. Was hat sich geändert? ------------------------------------------------------------

(cd "$LOKAL" && find . -type f ! -name "$STAND" -printf '%P\n' | LC_ALL=C sort | while IFS= read -r f; do
	printf '%s  %s\n' "$(sha256sum < "$f" | cut -d' ' -f1)" "$f"
done) > "$ARBEIT/neu.txt"

if grep -qxF "$STAND" "$ARBEIT/inhalt.txt"; then
	sftp_lauf "get $STAND -o $ARBEIT/alt.txt" > /dev/null
	echo "Stand des letzten Uploads gefunden ($(wc -l < "$ARBEIT/alt.txt") Dateien)."
else
	: > "$ARBEIT/alt.txt"
	echo "Kein Stand eines früheren Uploads – alle Dateien werden hochgeladen."
fi

# Geändert oder neu: Zeile (Prüfsumme + Pfad) steht nicht im alten Stand.
LC_ALL=C comm -23 <(LC_ALL=C sort "$ARBEIT/neu.txt") <(LC_ALL=C sort "$ARBEIT/alt.txt") | sed -E 's/^[0-9a-f]{64}  //' | LC_ALL=C sort > "$ARBEIT/hoch.txt"
# Entfallen: Pfad stand im alten Stand, ist aber nicht mehr im Paket.
LC_ALL=C comm -13 <(sed -E 's/^[0-9a-f]{64}  //' "$ARBEIT/neu.txt" | LC_ALL=C sort) <(sed -E 's/^[0-9a-f]{64}  //' "$ARBEIT/alt.txt" | LC_ALL=C sort) > "$ARBEIT/weg.txt"

hoch=$(wc -l < "$ARBEIT/hoch.txt")
weg=$(wc -l < "$ARBEIT/weg.txt")
echo "Hochzuladen: $hoch Dateien, zu löschen: $weg."
if [ "$hoch" -eq 0 ] && [ "$weg" -eq 0 ]; then
	echo "Der Server ist auf dem Stand des Pakets."
	exit 0
fi

# ----- 3. Hochladen -------------------------------------------------------------------------
# Die geänderten Dateien kommen in einen eigenen Baum und gehen als Ganzes hoch. Die
# Hauptdatei mit der Versionsnummer folgt zuletzt: Erst wenn Stylesheet und Skript da sind,
# verweist die Seite auf die neue Version.

mkdir "$ARBEIT/baum"
haupt_geaendert=nein
while IFS= read -r f; do
	if [ "$f" = "$HAUPTDATEI" ]; then haupt_geaendert=ja; continue; fi
	mkdir -p "$ARBEIT/baum/$(dirname "$f")"
	cp "$LOKAL/$f" "$ARBEIT/baum/$f"
done < "$ARBEIT/hoch.txt"

befehle=""
if [ -n "$(ls -A "$ARBEIT/baum")" ]; then
	befehle+="mirror --reverse --transfer-all --parallel=4 --no-perms --no-umask $ARBEIT/baum .
"
fi
if [ "$haupt_geaendert" = ja ]; then
	befehle+="put -O ./ $LOKAL/$HAUPTDATEI
"
fi
while IFS= read -r f; do
	[ -n "$f" ] && befehle+="rm -f '$f'
"
done < "$ARBEIT/weg.txt"
cp "$ARBEIT/neu.txt" "$ARBEIT/$STAND"
befehle+="put -O ./ $ARBEIT/$STAND
"

sftp_lauf "$befehle"
echo "Fertig: $hoch Dateien hochgeladen, $weg gelöscht."
