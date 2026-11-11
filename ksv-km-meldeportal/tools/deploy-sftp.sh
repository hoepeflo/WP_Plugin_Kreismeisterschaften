#!/usr/bin/env bash
# Lädt das gebaute Plugin per SFTP in den Plugin-Ordner der Website.
#
# Aufruf:   bash tools/deploy-sftp.sh <lokaler Ordner mit dem fertigen Plugin>
# Umgebung: KMM_SFTP_HOST, KMM_SFTP_USER, KMM_SFTP_PASS (Pflicht)
#           KMM_SFTP_PORT (Standard 22)
#           KMM_ZIEL      Zielordner relativ zum Startordner des SFTP-Benutzers
#                         (Standard "/": der Benutzer startet direkt im Plugin-Ordner)
#
# Braucht nur den sftp-Befehl von OpenSSH (ab 8.4), der auf jedem Linux und auf den
# GitHub-Runnern vorhanden ist – nichts muss nachinstalliert werden.
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
export KMM_SFTP_PASS
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

# Das Passwort reicht ein Hilfsprogramm an ssh weiter; es steht nie in einer Befehlszeile.
printf '#!/bin/sh\nprintf "%%s\\n" "$KMM_SFTP_PASS"\n' > "$ARBEIT/passwort"
chmod 700 "$ARBEIT/passwort"

# Pfad für eine sftp-Befehlsdatei in Anführungszeichen setzen.
q() { local s="${1//\\/\\\\}"; printf '"%s"' "${s//\"/\\\"}"; }

# sftp_lauf <Befehlsdatei>: führt die Befehle aus und bricht beim ersten Fehler ab
# (Befehle mit vorangestelltem „-“ dürfen scheitern). „BatchMode=no“ muss vor „-b“ stehen,
# sonst fragt ssh das Passwort gar nicht erst ab. Der Schlüssel des Servers wird beim
# ersten Kontakt angenommen.
sftp_lauf() {
	SSH_ASKPASS="$ARBEIT/passwort" SSH_ASKPASS_REQUIRE=force DISPLAY="${DISPLAY:-none}" \
	sftp -oBatchMode=no -oLogLevel=ERROR -oStrictHostKeyChecking=accept-new -oUserKnownHostsFile="$ARBEIT/known_hosts" \
		-oPreferredAuthentications=password,keyboard-interactive -oPubkeyAuthentication=no \
		-oNumberOfPasswordPrompts=1 -oConnectTimeout=30 -oServerAliveInterval=15 \
		-P "$PORT" -b "$1" "$KMM_SFTP_USER@$KMM_SFTP_HOST" < /dev/null
}
ins_ziel() { if [ -n "$ZIEL" ]; then printf 'cd %s\n' "$(q "$ZIEL")"; fi; }

# ----- 1. Anmeldung und Zielordner prüfen --------------------------------------------------

{ ins_ziel; echo "ls -1a"; } > "$ARBEIT/pruefen.sftp"
if ! sftp_lauf "$ARBEIT/pruefen.sftp" > "$ARBEIT/ausgabe.txt" 2> "$ARBEIT/fehler.txt"; then
	meldung="$(tr -d '\r' < "$ARBEIT/fehler.txt" | grep -v '^$' | grep -v '^Connection closed' | tail -2 | tr '\n' ' ')"
	if grep -qiE 'permission denied|authentication failed|too many authentication' "$ARBEIT/fehler.txt"; then
		fehler "SFTP-Anmeldung abgelehnt (${meldung}). Benutzername und Passwort in KMM_FTP_USER und KMM_FTP_PASS prüfen. Nichts hochgeladen."
	fi
	if grep -qiE 'no such file|not a directory' "$ARBEIT/fehler.txt"; then
		fehler "Angemeldet, aber den Zielordner „${ZIEL}“ gibt es nicht (${meldung}). Nichts hochgeladen."
	fi
	fehler "SFTP-Server nicht erreichbar (${meldung}). KMM_FTP_HOST prüfen. Nichts hochgeladen."
fi
grep -v '^sftp> ' "$ARBEIT/ausgabe.txt" | tr -d '\r' | sed 's/[[:space:]]*$//' > "$ARBEIT/inhalt.txt"
grep -qxF "$HAUPTDATEI" "$ARBEIT/inhalt.txt" || fehler "Angemeldet, aber im Zielordner „${ZIEL:-/}“ liegt keine $HAUPTDATEI. Der SFTP-Benutzer muss in wp-content/plugins/ksv-km-meldeportal/ starten (oder KMM_FTP_SERVER_DIR dorthin zeigen). Nichts hochgeladen."
if grep -qxE 'wp-load\.php|wp-config\.php|wp-admin' "$ARBEIT/inhalt.txt"; then
	fehler "Der Zielordner „${ZIEL:-/}“ ist ein WordPress-Hauptordner. Nichts hochgeladen."
fi
echo "Zielordner ist der Plugin-Ordner des KM-Portals."

# ----- 2. Was hat sich geändert? ------------------------------------------------------------

(cd "$LOKAL" && find . -type f ! -name "$STAND" -printf '%P\n' | LC_ALL=C sort | while IFS= read -r f; do
	printf '%s  %s\n' "$(sha256sum < "$f" | cut -d' ' -f1)" "$f"
done) > "$ARBEIT/neu.txt"

if grep -qxF "$STAND" "$ARBEIT/inhalt.txt"; then
	{ ins_ziel; printf 'get %s %s\n' "$(q "$STAND")" "$(q "$ARBEIT/alt.txt")"; } > "$ARBEIT/stand.sftp"
	sftp_lauf "$ARBEIT/stand.sftp" > /dev/null 2> "$ARBEIT/fehler.txt" || fehler "Der Stand des letzten Uploads ließ sich nicht lesen ($(tail -1 "$ARBEIT/fehler.txt")). Nichts hochgeladen."
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
# Erst die Ordner, dann die Dateien. Die Hauptdatei mit der Versionsnummer folgt zuletzt:
# Erst wenn Stylesheet und Skript da sind, verweist die Seite auf die neue Version.

cp "$ARBEIT/neu.txt" "$ARBEIT/$STAND"
{
	ins_ziel
	# Alle Elternordner der geänderten Dateien, Eltern vor Kindern; vorhandene Ordner stören nicht.
	while IFS= read -r f; do
		d="$(dirname "$f")"
		while [ "$d" != "." ]; do echo "$d"; d="$(dirname "$d")"; done
	done < "$ARBEIT/hoch.txt" | LC_ALL=C sort -u | while IFS= read -r d; do
		printf -- '-mkdir %s\n' "$(q "$d")"
	done
	while IFS= read -r f; do
		if [ "$f" != "$HAUPTDATEI" ]; then printf 'put %s %s\n' "$(q "$LOKAL/$f")" "$(q "$f")"; fi
	done < "$ARBEIT/hoch.txt"
	if grep -qxF "$HAUPTDATEI" "$ARBEIT/hoch.txt"; then
		printf 'put %s %s\n' "$(q "$LOKAL/$HAUPTDATEI")" "$(q "$HAUPTDATEI")"
	fi
	while IFS= read -r f; do
		if [ -n "$f" ]; then printf -- '-rm %s\n' "$(q "$f")"; fi
	done < "$ARBEIT/weg.txt"
	printf 'put %s %s\n' "$(q "$ARBEIT/$STAND")" "$(q "$STAND")"
} > "$ARBEIT/hochladen.sftp"

if ! sftp_lauf "$ARBEIT/hochladen.sftp" > "$ARBEIT/ausgabe.txt" 2> "$ARBEIT/fehler.txt"; then
	# Meldungen zu schon vorhandenen Ordnern sind erwartet und sagen nichts über den Abbruch.
	grep -viE "couldn't create directory|mkdir .*: failure" "$ARBEIT/fehler.txt" | tail -5 || true
	fehler "Der Upload ist abgebrochen. Der Stand auf dem Server wurde nicht fortgeschrieben; der nächste Lauf lädt die fehlenden Dateien erneut."
fi
echo "Fertig: $hoch Dateien hochgeladen, $weg gelöscht."
