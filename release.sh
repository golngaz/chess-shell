#!/usr/bin/env bash
#
# Construit un zip de release autonome : le jeu + la dernière version de
# Stockfish (Linux x86-64), téléchargée à la volée depuis les releases
# officielles GitHub.
#
# Usage : ./release.sh <version>        ex. ./release.sh 1.0.0
# Sortie : release/chess-shell-<version>-linux-x86-64.zip (+ .sha256)
#
set -euo pipefail

SF_REPO="official-stockfish/Stockfish"
SF_ASSET="stockfish-linux-x86-64-universal.tar.gz"
PLATFORM="linux-x86-64"
FILES=(chess.php README.md LICENSE)

die() { echo "Erreur : $*" >&2; exit 1; }

# --- Arguments ---------------------------------------------------------------
if [[ $# -ne 1 || -z "$1" || "$1" == -h || "$1" == --help ]]; then
    echo "Usage : $0 <version>   (ex. $0 1.0.0)" >&2
    exit 1
fi
VERSION="${1#v}"
[[ "$VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]] || die "version invalide : '$1'"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="$ROOT/release"
NAME="chess-shell-$VERSION-$PLATFORM"
ZIP="$OUT_DIR/$NAME.zip"

for cmd in curl tar zip php sha256sum; do
    command -v "$cmd" >/dev/null || die "commande requise introuvable : $cmd"
done
for f in "${FILES[@]}"; do
    [[ -f "$ROOT/$f" ]] || die "fichier manquant : $f"
done

# Avertir si on empaquette des modifications non commitées
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
   && [[ -n "$(git -C "$ROOT" status --porcelain -- "${FILES[@]}")" ]]; then
    echo "Attention : modifications non commitées dans ${FILES[*]} — elles seront incluses." >&2
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --- Dernière release de Stockfish ------------------------------------------
echo "→ Recherche de la dernière version de Stockfish…"
curl -fsSL -H "Accept: application/vnd.github+json" \
    "https://api.github.com/repos/$SF_REPO/releases/latest" -o "$TMP/release.json" \
    || die "impossible d'interroger l'API GitHub"

read -r SF_TAG SF_URL < <(php -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    foreach ($r["assets"] ?? [] as $a) {
        if ($a["name"] === $argv[2]) { echo $r["tag_name"], " ", $a["browser_download_url"], "\n"; exit(0); }
    }
    exit(1);
' "$TMP/release.json" "$SF_ASSET") || die "asset $SF_ASSET introuvable dans la dernière release"

echo "→ Téléchargement de Stockfish $SF_TAG ($SF_ASSET)…"
curl -fL --progress-bar "$SF_URL" -o "$TMP/$SF_ASSET" || die "échec du téléchargement"

mkdir -p "$TMP/sf"
tar -xzf "$TMP/$SF_ASSET" -C "$TMP/sf"
SF_BIN="$(find "$TMP/sf" -type f -name 'stockfish-linux-x86-64*' | head -n1)"
[[ -n "$SF_BIN" ]] || die "binaire Stockfish introuvable dans l'archive"
SF_DIR="$(dirname "$SF_BIN")"
chmod +x "$SF_BIN"
"$SF_BIN" <<<"quit" >/dev/null 2>&1 || echo "Attention : le binaire ne s'exécute pas sur cette machine (normal si autre architecture)." >&2

# --- Assemblage --------------------------------------------------------------
echo "→ Assemblage de $NAME…"
PKG="$TMP/$NAME"
mkdir -p "$PKG"
cp "${FILES[@]/#/$ROOT/}" "$PKG/"
# Dossier Stockfish complet (binaire + sources + licence GPLv3) : le jeu
# cherche ./stockfish/stockfish-linux-x86-64-universal
cp -r "$SF_DIR" "$PKG/stockfish"
echo "$SF_TAG" > "$PKG/stockfish/VERSION"

# Lanceur
cat > "$PKG/chess-shell" <<'EOF'
#!/usr/bin/env sh
# Lance le jeu ; les options sont transmises (ex. ./chess-shell --level=3 --color=black)
exec php "$(dirname "$0")/chess.php" "$@"
EOF
chmod +x "$PKG/chess-shell"

# --- Zip ---------------------------------------------------------------------
mkdir -p "$OUT_DIR"
rm -f "$ZIP" "$ZIP.sha256"
(cd "$TMP" && zip -qr -9 "$ZIP" "$NAME")
(cd "$OUT_DIR" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")

echo
echo "✔ Release créée : release/$NAME.zip ($(du -h "$ZIP" | cut -f1), Stockfish $SF_TAG)"
echo "  Somme de contrôle : release/$NAME.zip.sha256"
echo "  Pour l'utiliser : unzip $NAME.zip && ./$NAME/chess-shell"
