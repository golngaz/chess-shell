<?php
declare(strict_types=1);

/**
 * Chess CLI — jouez contre Stockfish au clavier.
 *
 * Usage : php chess.php [--level=1..5] [--color=white|black|random]
 * Coups  : notation coordonnées, ex. e2e4, g1f3, e7e8q (promotion), o-o / o-o-o
 */

const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

/**
 * Niveaux "humains" : l'IA ne voit qu'à une profondeur limitée, et choisit
 * parmi plusieurs coups candidats avec une part d'imprécision / de gaffe.
 *  depth    : profondeur de calcul (en demi-coups)
 *  multipv  : nombre de coups candidats considérés
 *  margin   : écart max (centipions) toléré par rapport au meilleur coup
 *  temp     : "température" — plus c'est haut, plus le choix est aléatoire
 *  blunder  : probabilité de jouer un coup légal au hasard (gaffe pure)
 */
const LEVELS = [
    1 => ['name' => 'Débutant',      'elo' => '~400',  'depth' => 1, 'multipv' => 12, 'margin' => 400, 'temp' => 160, 'blunder' => 0.20],
    2 => ['name' => 'Novice',        'elo' => '~550',  'depth' => 2, 'multipv' => 8,  'margin' => 250, 'temp' => 110, 'blunder' => 0.12],
    3 => ['name' => 'Joueur de club','elo' => '~700',  'depth' => 3, 'multipv' => 6,  'margin' => 160, 'temp' => 75,  'blunder' => 0.07],
    4 => ['name' => 'Intermédiaire', 'elo' => '~850',  'depth' => 4, 'multipv' => 5,  'margin' => 100, 'temp' => 50,  'blunder' => 0.04],
    5 => ['name' => 'Confirmé',      'elo' => '~1000', 'depth' => 6, 'multipv' => 4,  'margin' => 60,  'temp' => 30,  'blunder' => 0.02],
];

// ---------------------------------------------------------------------------
// Moteur UCI
// ---------------------------------------------------------------------------
final class Engine
{
    private $proc;
    private $in;
    private $out;

    public function __construct(string $path)
    {
        $this->proc = proc_open([$path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($this->proc)) {
            throw new RuntimeException("Impossible de lancer Stockfish : $path");
        }
        [$this->in, $this->out] = [$pipes[0], $pipes[1]];
        $this->send('uci');
        $this->readUntil('uciok');
        $this->send('setoption name Threads value 1');
        $this->send('setoption name Hash value 16');
        $this->send('isready');
        $this->readUntil('readyok');
    }

    public function send(string $cmd): void
    {
        @fwrite($this->in, $cmd . "\n");
        @fflush($this->in);
    }

    /** @return string[] lignes lues jusqu'à (et incluant) celle commençant par $prefix */
    public function readUntil(string $prefix): array
    {
        $lines = [];
        while (($line = fgets($this->out)) !== false) {
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if (str_starts_with($line, $prefix)) {
                return $lines;
            }
        }
        throw new RuntimeException('Stockfish s\'est arrêté de manière inattendue.');
    }

    public function setPosition(array $moves): void
    {
        $this->send('position startpos' . ($moves ? ' moves ' . implode(' ', $moves) : ''));
    }

    /** @return array{fen:string,key:string,checkers:bool,legal:string[]} */
    public function inspect(array $moves): array
    {
        $this->setPosition($moves);
        $this->send('go perft 1');
        $legal = [];
        foreach ($this->readUntil('Nodes searched') as $l) {
            if (preg_match('/^([a-h][1-8][a-h][1-8][qrbn]?): \d+$/', $l, $m)) {
                $legal[] = $m[1];
            }
        }
        $this->send('d');
        $fen = $key = '';
        $checkers = false;
        foreach ($this->readUntil('Checkers') as $l) {
            if (str_starts_with($l, 'Fen: ')) $fen = substr($l, 5);
            if (str_starts_with($l, 'Key: ')) $key = substr($l, 5);
            if (str_starts_with($l, 'Checkers:')) $checkers = trim(substr($l, 9)) !== '';
        }
        return compact('fen', 'key', 'checkers', 'legal');
    }

    /** @return array<int,array{move:string,score:int}> candidats triés par multipv */
    public function analyse(array $moves, int $depth, int $multipv): array
    {
        $this->send("setoption name MultiPV value $multipv");
        $this->setPosition($moves);
        $this->send("go depth $depth");
        $cands = [];
        foreach ($this->readUntil('bestmove') as $l) {
            if (!str_starts_with($l, 'info') || !str_contains($l, ' pv ')) continue;
            if (!preg_match('/ multipv (\d+)/', $l, $mp)) continue;
            if (!preg_match('/ score (cp|mate) (-?\d+)/', $l, $sc)) continue;
            if (!preg_match('/ pv ([a-h][1-8][a-h][1-8][qrbn]?)/', $l, $pv)) continue;
            $v = (int)$sc[2];
            $score = $sc[1] === 'cp' ? $v : ($v > 0 ? 100000 - $v : -100000 - $v);
            $cands[(int)$mp[1]] = ['move' => $pv[1], 'score' => $score];
        }
        ksort($cands);
        return array_values($cands);
    }

    public function __destruct()
    {
        if (is_resource($this->in)) {
            $this->send('quit');
            fclose($this->in);
        }
        if (is_resource($this->out)) fclose($this->out);
        if (is_resource($this->proc)) proc_close($this->proc);
    }
}

// ---------------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------------
final class Ui
{
    private const GLYPHS = ['k' => '♚', 'q' => '♛', 'r' => '♜', 'b' => '♝', 'n' => '♞', 'p' => '♟'];
    private const VALUES = ['q' => 9, 'r' => 5, 'b' => 3, 'n' => 3, 'p' => 1];

    public static function c(string $s, string $code): string
    {
        return "\e[{$code}m{$s}\e[0m";
    }

    /** @return array<string,string> case => pièce */
    public static function parseFen(string $fen): array
    {
        $board = [];
        $rows = explode('/', explode(' ', $fen)[0]);
        foreach ($rows as $i => $row) {
            $rank = 8 - $i;
            $file = 0;
            foreach (str_split($row) as $ch) {
                if (ctype_digit($ch)) {
                    $file += (int)$ch;
                } else {
                    $board[chr(97 + $file) . $rank] = $ch;
                    $file++;
                }
            }
        }
        return $board;
    }

    /** Pièces moyennes en pixel art (12 × 4 pixels) pour une case de 7 × 3 caractères = 14 × 6 pixels. */
    private const PIXELS_M = [
        'p' => ['............', '.....##.....', '....####....', '...######...'],
        'r' => ['............', '...#.##.#...', '...######...', '...######...'],
        'n' => ['............', '.....##.....', '...####.....', '....######..'],
        'b' => ['.....##.....', '....#.##....', '.....##.....', '...######...'],
        'q' => ['............', '..#..##..#..', '...######...', '...######...'],
        'k' => ['.....##.....', '....####....', '....####....', '..########..'],
    ];

    /**
     * Grosses pièces en "pixel art" (14 × 6 pixels), rendues avec des quarts de
     * caractère (▗▖▝▘…) dans une case de 8 × 4 caractères = 16 × 8 pixels,
     * soit une marge d'un pixel tout autour de la pièce.
     */
    private const PIXELS = [
        'p' => ['..............', '......##......', '.....####.....', '......##......', '.....####.....', '...########...'],
        'r' => ['..............', '..............', '...##.##.##...', '...########...', '...########...', '...########...'],
        'n' => ['..............', '..............', '......#.#.....', '....#####.....', '..####.##.....', '....######....'],
        'b' => ['......##......', '.....##.#.....', '....##.###....', '.....####.....', '......##......', '...########...'],
        'q' => ['..............', '..............', '..#...##...#..', '...########...', '....######....', '...########...'],
        'k' => ['......##......', '....######....', '......##......', '...########...', '....######....', '..##########..'],
    ];

    /** @return string[] les lignes de texte de la pièce, pour une case de $w × $h caractères */
    private static function pixelSprite(string $piece, int $w, int $h): array
    {
        static $cache = [];
        $key = "$piece:$w:$h";
        if (isset($cache[$key])) return $cache[$key];
        $quads = [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'];
        $grid = array_fill(0, 2 * $h, str_repeat('.', 2 * $w));
        $src = $h >= 4 ? self::PIXELS[$piece] : self::PIXELS_M[$piece];
        $offY = intdiv(2 * $h - count($src), 2);
        $offX = intdiv(2 * $w - strlen($src[0]), 2);
        foreach ($src as $y => $row) {
            $grid[$y + $offY] = substr_replace($grid[$y + $offY], $row, $offX, strlen($row));
        }
        $lines = [];
        for ($r = 0; $r < $h; $r++) {
            $line = '';
            for ($c = 0; $c < $w; $c++) {
                $i = ($grid[2 * $r][2 * $c] === '#' ? 1 : 0) | ($grid[2 * $r][2 * $c + 1] === '#' ? 2 : 0)
                   | ($grid[2 * $r + 1][2 * $c] === '#' ? 4 : 0) | ($grid[2 * $r + 1][2 * $c + 1] === '#' ? 8 : 0);
                $line .= $quads[$i];
            }
            $lines[] = $line;
        }
        return $cache[$key] = $lines;
    }

    /** Taille des cases [largeur, hauteur] : 1 = compact (♟), 2 = moyen, 3 = grand. */
    public const SIZES = [1 => [3, 1], 2 => [7, 3], 3 => [8, 4]];

    /** @return array{0:int,1:int} [largeur, hauteur] du plateau affiché (coordonnées comprises) */
    public static function boardSize(int $size): array
    {
        [$w, $h] = self::SIZES[$size];
        return [3 + 8 * $w, 8 * $h + 1];
    }

    /** @return array{0:int,1:int} [lignes, colonnes] du terminal */
    public static function termSize(): array
    {
        $out = trim((string)@shell_exec('stty size < /dev/tty 2>/dev/null'));
        if (preg_match('/^(\d+) (\d+)$/', $out, $m) && (int)$m[1] > 0) return [(int)$m[1], (int)$m[2]];
        $rows = (int)@shell_exec('tput lines 2>/dev/null');
        $cols = (int)@shell_exec('tput cols 2>/dev/null');
        return [$rows > 0 ? $rows : 24, $cols > 0 ? $cols : 80];
    }

    /** Tronque une ligne (avec codes couleur) à $width caractères visibles. */
    public static function clip(string $s, int $width): string
    {
        if ($width <= 0) return '';
        $out = '';
        $n = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($part === '') continue;
            if ($part[0] === "\e") { $out .= $part; continue; }
            $len = mb_strlen($part);
            if ($n + $len > $width) {
                return $out . mb_substr($part, 0, $width - $n) . "\e[0m";
            }
            $out .= $part;
            $n += $len;
        }
        return $out;
    }

    /** Coupe un texte en lignes d'au plus $width caractères visibles. */
    public static function wrap(string $text, int $width): array
    {
        $width = max(10, $width);
        $lines = [];
        $cur = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $cur === '' ? $word : "$cur $word";
            if (mb_strlen($candidate) <= $width) {
                $cur = $candidate;
                continue;
            }
            if ($cur !== '') $lines[] = $cur;
            while (mb_strlen($word) > $width) {
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }
            $cur = $word;
        }
        $lines[] = $cur;
        return $lines;
    }

    /** @return string[] les lignes du plateau (sans retour à la ligne) */
    public static function render(string $fen, bool $whiteBottom, ?string $lastMove, bool $inCheck, int $size, ?string $selected = null, array $targets = []): array
    {
        [$w, $h] = self::SIZES[$size];
        $mid = intdiv($h - 1, 2);
        $pad = intdiv($w - 1, 2);
        $board = self::parseFen($fen);
        $hl = $lastMove ? [substr($lastMove, 0, 2), substr($lastMove, 2, 2)] : [];
        $turn = explode(' ', $fen)[1];
        $kingSq = null;
        if ($inCheck) {
            foreach ($board as $sq => $p) {
                if ($p === ($turn === 'w' ? 'K' : 'k')) $kingSq = $sq;
            }
        }

        $ranks = $whiteBottom ? range(8, 1) : range(1, 8);
        $files = $whiteBottom ? range(0, 7) : range(7, 0);
        $out = [];
        foreach ($ranks as $r) {
            for ($line = 0; $line < $h; $line++) {
                $row = $line === $mid ? self::c(" $r ", '2') : '   ';
                foreach ($files as $f) {
                    $sq = chr(97 + $f) . $r;
                    $light = ($f + $r) % 2 === 1;
                    $bg = $light ? '48;5;180' : '48;5;94';
                    if (in_array($sq, $hl, true)) $bg = $light ? '48;5;186' : '48;5;142';
                    if ($sq === $kingSq) $bg = '48;5;160';
                    $p = $board[$sq] ?? null;
                    $isTarget = in_array($sq, $targets, true);
                    if ($sq === $selected) $bg = '48;5;71';
                    elseif ($isTarget && $p !== null) $bg = '48;5;174';
                    $cell = str_repeat(' ', $w);
                    $fg = '';
                    if ($p === null && $isTarget && $line === $mid) {
                        $cell = str_repeat(' ', $pad) . '●' . str_repeat(' ', $w - 1 - $pad);
                        $fg = ';38;5;22';
                    }
                    if ($p !== null) {
                        $k = strtolower($p);
                        $fg = ctype_upper($p) ? ';38;5;231' : ';38;5;232';
                        // signe distinctif du roi : croix rouge (jaune s'il est en échec, sur case rouge)
                        $kingMark = $sq === $kingSq ? ';38;5;226' : ';38;5;196';
                        if ($k === 'k' && $size >= 2 && $line < 2) $fg = $kingMark;
                        if ($k === 'k' && $size === 1) $fg .= ';4;58;5;196';
                        if ($size === 3) {
                            $cell = self::pixelSprite($k, $w, $h)[$line];
                        } elseif ($size === 2) {
                            $cell = self::pixelSprite($k, $w, $h)[$line];
                        } elseif ($line === $mid) {
                            $cell = str_repeat(' ', $pad) . self::GLYPHS[$k] . str_repeat(' ', $w - 1 - $pad);
                        }
                    }
                    $row .= "\e[{$bg}{$fg}m{$cell}\e[0m";
                }
                $out[] = $row;
            }
        }
        $row = '   ';
        foreach ($files as $f) {
            $row .= self::c(str_repeat(' ', $pad) . chr(97 + $f) . str_repeat(' ', $w - 1 - $pad), '2');
        }
        $out[] = $row;
        return $out;
    }

    /** Pièces capturées + avantage matériel, du point de vue des blancs. */
    public static function material(string $fen): array
    {
        $start = ['q' => 1, 'r' => 2, 'b' => 2, 'n' => 2, 'p' => 8];
        $cnt = ['w' => [], 'b' => []];
        foreach (self::parseFen($fen) as $p) {
            $side = ctype_upper($p) ? 'w' : 'b';
            $k = strtolower($p);
            $cnt[$side][$k] = ($cnt[$side][$k] ?? 0) + 1;
        }
        $lost = ['w' => '', 'b' => ''];
        $score = ['w' => 0, 'b' => 0];
        foreach (['w', 'b'] as $side) {
            foreach ($start as $k => $n) {
                $have = $cnt[$side][$k] ?? 0;
                $score[$side] += $have * self::VALUES[$k];
                if ($have < $n) $lost[$side] .= str_repeat(self::GLYPHS[$k], $n - $have);
            }
        }
        return ['lostWhite' => $lost['w'], 'lostBlack' => $lost['b'], 'diff' => $score['w'] - $score['b']];
    }
}

// ---------------------------------------------------------------------------
// Terminal : mode brut + souris (clics)
// ---------------------------------------------------------------------------
final class Term
{
    private static ?string $saved = null;

    /** Passe le terminal en mode caractère par caractère et active les clics souris. */
    public static function enable(): void
    {
        if (self::$saved !== null || !stream_isatty(STDIN)) return;
        $saved = trim((string)shell_exec('stty -g < /dev/tty 2>/dev/null'));
        if ($saved === '') return;
        self::$saved = $saved;
        shell_exec('stty -icanon -echo min 1 time 0 < /dev/tty 2>/dev/null');
        echo "\e[?1000h\e[?1006h"; // clics souris, encodage SGR
    }

    public static function disable(): void
    {
        if (self::$saved === null) return;
        echo "\e[?1000l\e[?1006l";
        shell_exec('stty ' . escapeshellarg(self::$saved) . ' < /dev/tty 2>/dev/null');
        self::$saved = null;
    }

    public static function active(): bool
    {
        return self::$saved !== null;
    }

    /** Appelé après un retour de Ctrl-Z (fg) pour redessiner l'écran. */
    public static ?Closure $onResume = null;

    /** Ctrl-Z : on rend un terminal propre au shell, puis on se remet en mode jeu au retour. */
    public static function suspend(): void
    {
        $wasActive = self::active();
        self::disable();
        pcntl_signal(SIGTSTP, SIG_DFL);
        posix_kill(getmypid(), SIGTSTP); // le processus s'arrête ici jusqu'à "fg"
        pcntl_signal(SIGTSTP, fn() => self::suspend(), false);
        if ($wasActive) {
            self::enable();
            if (self::$onResume) (self::$onResume)();
        }
    }

    /** Jette tout ce qui a été tapé/cliqué pendant que Stockfish réfléchissait. */
    public static function drain(): void
    {
        if (!self::active()) return;
        stream_set_blocking(STDIN, false);
        while (($d = fread(STDIN, 4096)) !== false && $d !== '');
        stream_set_blocking(STDIN, true);
    }
}

// ---------------------------------------------------------------------------
// Partie
// ---------------------------------------------------------------------------
final class Game
{
    private array $moves = [];
    private array $keys = [];
    private array $state;
    private bool $whiteBottom;
    /** @var array<int,array{0:string,1:string}> messages [texte, code couleur] */
    private array $messages = [];
    public static int $size = 3;
    private int $drawnSize = 3;
    private ?int $notifiedSize = null;
    private bool $panelOverflow = false;
    private ?string $selected = null;
    private string $buf = '';
    private array $history = [];

    public function __construct(private Engine $engine, private int $level, private string $playerColor)
    {
        $this->whiteBottom = $playerColor === 'w';
        $this->refresh();
    }

    private function refresh(): void
    {
        $this->state = $this->engine->inspect($this->moves);
    }

    private function play(string $move): void
    {
        $this->moves[] = $move;
        $this->refresh();
        $this->keys[] = $this->state['key'];
    }

    private function turn(): string
    {
        return explode(' ', $this->state['fen'])[1];
    }

    /** @return string|null raison de fin de partie */
    private function gameOver(): ?string
    {
        if (!$this->state['legal']) {
            if ($this->state['checkers']) {
                $winner = $this->turn() === 'w' ? 'Noirs' : 'Blancs';
                return "Échec et mat ! Les $winner gagnent.";
            }
            return 'Pat — partie nulle.';
        }
        $half = (int)(explode(' ', $this->state['fen'])[4] ?? 0);
        if ($half >= 100) return 'Règle des 50 coups — partie nulle.';
        $counts = array_count_values($this->keys);
        if (($counts[$this->state['key']] ?? 0) >= 3) return 'Triple répétition — partie nulle.';
        if ($this->insufficientMaterial()) return 'Matériel insuffisant — partie nulle.';
        return null;
    }

    private function insufficientMaterial(): bool
    {
        $minor = [];
        foreach (Ui::parseFen($this->state['fen']) as $sq => $p) {
            $k = strtolower($p);
            if ($k === 'k') continue;
            if (in_array($k, ['p', 'r', 'q'], true)) return false;
            $minor[] = [$k, (ord($sq[0]) + (int)$sq[1]) % 2];
        }
        if (count($minor) <= 1) return true;
        // Seulement des fous, tous sur la même couleur de case
        $colors = array_unique(array_map(fn($m) => $m[1], $minor));
        return count(array_filter($minor, fn($m) => $m[0] !== 'b')) === 0 && count($colors) === 1;
    }

    /** Ajoute un message (éventuellement multi-ligne) à afficher au prochain rendu. */
    private function say(string $text, string $code = ''): void
    {
        foreach (explode("\n", $text) as $line) $this->messages[] = [$line, $code];
    }

    /**
     * Dessine l'écran en s'adaptant au terminal : on prend la plus grande taille
     * de plateau (≤ taille choisie) qui tient en hauteur, avec le panneau
     * d'infos à droite si la largeur le permet, sinon en dessous.
     */
    private function draw(): void
    {
        [$rows, $cols] = Ui::termSize();
        $layout = null;
        for ($s = self::$size; $s >= 1 && $layout === null; $s--) {
            [$bw, $bh] = Ui::boardSize($s);
            if ($cols >= $bw + 3 + 30 && $bh + 1 <= $rows) {
                $layout = ['side', $s, $bw, $bh];
            } elseif ($bw <= $cols && $bh + 1 + 8 <= $rows) {
                $layout = ['below', $s, $bw, $bh];
            }
        }
        [$bw, $bh] = Ui::boardSize(1);
        [$mode, $size, $bw, $bh] = $layout ?? ['below', 1, $bw, $bh];

        // prévenir une seule fois quand la taille est réduite faute de place
        if ($size < self::$size && $this->notifiedSize !== $size) {
            array_unshift($this->messages, ["Terminal trop petit : plateau en taille $size (agrandissez la fenêtre).", '2']);
        }
        $this->notifiedSize = $size < self::$size ? $size : null;

        $this->drawnSize = $size;
        $targets = [];
        if ($this->selected !== null) {
            foreach ($this->state['legal'] as $m) {
                if (str_starts_with($m, $this->selected)) $targets[] = substr($m, 2, 2);
            }
        }
        $board = Ui::render($this->state['fen'], $this->whiteBottom, end($this->moves) ?: null,
            $this->state['checkers'], $size, $this->selected, $targets);
        $panel = $mode === 'side' ? $this->panel($cols - $bw - 4, $bh) : null;
        // panneau latéral trop court (ex. l'aide) : on le met sous le plateau si ça y tient mieux
        if ($panel !== null && $this->panelOverflow && $rows - $bh - 1 > $bh) {
            $mode = 'below';
            $panel = null;
        }
        if ($mode === 'side') {
            $lines = [];
            foreach ($board as $i => $l) $lines[] = $l . '   ' . ($panel[$i] ?? '');
        } else {
            $lines = array_merge($board, $this->panel($cols - 1, max(0, $rows - $bh - 1)));
        }
        $this->messages = [];
        echo "\e[H\e[2J" . implode("\n", $lines) . "\n";
    }

    /** @return string[] lignes du panneau d'infos, au plus $maxLines */
    private function panel(int $width, int $maxLines): array
    {
        $lvl = LEVELS[$this->level];
        $mat = Ui::material($this->state['fen']);
        $names = ['w' => 'Blancs', 'b' => 'Noirs'];
        $engineColor = $this->playerColor === 'w' ? 'b' : 'w';
        // pièces prises PAR chaque camp = pièces perdues par l'adversaire
        $taken = ['w' => $mat['lostBlack'], 'b' => $mat['lostWhite']];
        $adv = ['w' => max(0, $mat['diff']), 'b' => max(0, -$mat['diff'])];
        $sideLine = function (string $label, string $color) use ($names, $taken, $adv): string {
            return Ui::c("$label ({$names[$color]})", '1') . ' ' . Ui::c($taken[$color], '2')
                . ($adv[$color] ? Ui::c(" +{$adv[$color]}", '2') : '');
        };

        $head = [
            Ui::c(' ♞ Chess vs Stockfish ', '1;7'),
            Ui::c("Niveau {$this->level}/5 — {$lvl['name']}", '36'),
            Ui::c("{$lvl['elo']} Elo · profondeur {$lvl['depth']}", '2'),
            '',
            $sideLine('Stockfish', $engineColor),
            $sideLine('Vous', $this->playerColor),
            '',
        ];

        $tail = [];
        if ($this->state['checkers'] && $this->state['legal']) $tail[] = Ui::c('Échec !', '1;31');
        foreach ($this->messages as [$text, $code]) {
            foreach (Ui::wrap($text, $width) as $l) $tail[] = $code === '' ? $l : Ui::c($l, $code);
        }
        if ($tail) array_unshift($tail, '');

        $hist = [];
        foreach (array_chunk($this->moves, 2) as $i => $pair) {
            $hist[] = Ui::c(str_pad(($i + 1) . '.', 4, ' ', STR_PAD_LEFT), '2') . ' ' . implode('  ', $pair);
        }
        $this->panelOverflow = false;
        if ($maxLines <= 0) return [];

        // Manque de place : on sacrifie d'abord les lignes décoratives de l'en-tête
        $wantHist = min(3, count($hist));
        foreach ([3, 6, 0, 2, 1] as $i) {
            if (count($head) + count($tail) + 1 + $wantHist <= $maxLines) break;
            unset($head[$i]);
        }
        $head = array_values($head);

        if (count($head) + count($tail) + 1 + $wantHist > $maxLines) {
            // toujours trop long : les messages passent avant l'historique,
            // et on les coupe par la fin (le début de l'aide reste lisible)
            $histBlock = [];
            $room = $maxLines - count($head);
            if (count($tail) > $room) {
                $this->panelOverflow = true;
                $tail = $room >= 2 ? array_merge(array_slice($tail, 0, $room - 1), [Ui::c('…', '2')]) : [];
            }
        } else {
            $room = $maxLines - count($head) - count($tail) - 1;
            $histBlock = array_merge([Ui::c('Coups :', '2')], $room > 0 ? array_slice($hist, -$room) : []);
        }

        $lines = array_slice(array_merge($head, $histBlock, $tail), 0, $maxLines);
        return array_map(fn($l) => Ui::clip($l, $width), $lines);
    }

    private function engineMove(): void
    {
        $lvl = LEVELS[$this->level];
        $start = microtime(true);

        $legal = $this->state['legal'];
        if (mt_rand() / mt_getrandmax() < $lvl['blunder']) {
            $move = $legal[array_rand($legal)];
        } else {
            $cands = $this->engine->analyse($this->moves, $lvl['depth'], $lvl['multipv']);
            $move = $cands ? $this->pickHuman($cands, $lvl['margin'], $lvl['temp']) : $legal[array_rand($legal)];
        }

        // petite pause pour un rythme plus "humain"
        $elapsed = microtime(true) - $start;
        $wait = 0.4 + mt_rand(0, 600) / 1000;
        if ($elapsed < $wait) usleep((int)(($wait - $elapsed) * 1e6));

        $this->play($move);
        $this->say("Stockfish joue $move", '1;33');
    }

    /** Choix pondéré parmi les candidats proches du meilleur (softmax). */
    private function pickHuman(array $cands, int $margin, int $temp): string
    {
        $best = $cands[0]['score'];
        // Toujours voir un mat en 1 / éviter les mats évidents
        if ($best > 90000) return $cands[0]['move'];
        $pool = array_values(array_filter($cands, fn($c) => $c['score'] >= $best - $margin));
        $weights = array_map(fn($c) => exp(($c['score'] - $best) / $temp), $pool);
        $r = mt_rand() / mt_getrandmax() * array_sum($weights);
        foreach ($pool as $i => $c) {
            $r -= $weights[$i];
            if ($r <= 0) return $c['move'];
        }
        return $pool[0]['move'];
    }

    private function parseMove(string $in): ?string
    {
        $in = strtolower(str_replace([' ', '-', 'x', '='], '', $in));
        $castle = ['oo' => 'g', '00' => 'g', 'ooo' => 'c', '000' => 'c'];
        if (isset($castle[$in])) {
            $rank = $this->turn() === 'w' ? '1' : '8';
            $in = 'e' . $rank . $castle[$in] . $rank;
        }
        if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $in)) return null;
        $legal = $this->state['legal'];
        if (in_array($in, $legal, true)) return $in;
        if (strlen($in) === 4 && in_array($in . 'q', $legal, true)) return $in . 'q';
        return '';
    }

    /**
     * Lit une ligne au clavier, ou un clic souris.
     * @return array{0:string,1?:mixed,2?:int} ['line', texte] | ['click', x, y] | ['eof']
     */
    private function input(string $prompt): array
    {
        if (!Term::active()) {
            $l = readline($prompt);
            if ($l === false) return ['eof'];
            if (trim($l) !== '') readline_add_history($l);
            return ['line', $l];
        }
        echo $prompt . $this->buf;
        Term::$onResume = function () use ($prompt) {
            $this->draw();
            echo $prompt . $this->buf;
        };
        $histPos = count($this->history);
        $pending = null;
        while (true) {
            if ($pending === null) {
                // attendre une touche via select : interrompu par les signaux
                // (Ctrl-C, Ctrl-Z…) contrairement à fread que PHP relance
                $r = [STDIN]; $w = $e = null;
                if (!@stream_select($r, $w, $e, null)) continue;
            }
            $c = $pending ?? fread(STDIN, 1);
            $pending = null;
            if ($c === false || $c === '') {
                if (feof(STDIN)) return ['eof'];
                continue;
            }
            if ($c === "\n" || $c === "\r") {
                echo "\n";
                $line = $this->buf;
                $this->buf = '';
                if (trim($line) !== '') $this->history[] = $line;
                return ['line', $line];
            }
            if ($c === "\x7f" || $c === "\x08") {
                if ($this->buf !== '') {
                    $this->buf = mb_substr($this->buf, 0, -1);
                    echo "\x08 \x08";
                }
                continue;
            }
            if ($c === "\x04") {
                if ($this->buf === '') return ['eof'];
                continue;
            }
            if ($c === "\e") {
                // Échap seul : rien ne suit immédiatement → on l'ignore
                $r = [STDIN]; $w = $e = null;
                if (!@stream_select($r, $w, $e, 0, 80000)) continue;
                $n = fread(STDIN, 1);
                if ($n !== '[' && $n !== 'O') { $pending = $n; continue; } // Alt+touche : on garde la touche
                $seq = '';
                do {
                    $b = fread(STDIN, 1);
                    if ($b === false || $b === '') break;
                    $seq .= $b;
                } while (ord($b) < 0x40 || ord($b) > 0x7e);
                // Clic souris ancien format (X10) : ESC [ M b x y
                if ($seq === 'M') {
                    $raw = '';
                    while (strlen($raw) < 3 && ($b = fread(STDIN, 1)) !== false && $b !== '') $raw .= $b;
                    if (strlen($raw) === 3 && ord($raw[0]) - 32 === 0) {
                        echo "\n";
                        return ['click', ord($raw[1]) - 32, ord($raw[2]) - 32];
                    }
                    continue;
                }
                // Clic souris SGR : ESC [ < bouton ; x ; y M
                if (preg_match('/^<(\d+);(\d+);(\d+)([Mm])$/', $seq, $m)) {
                    if ($m[4] === 'M' && (int)$m[1] === 0) {
                        echo "\n";
                        return ['click', (int)$m[2], (int)$m[3]];
                    }
                    continue;
                }
                // Flèches haut / bas : historique
                if ($seq === 'A' || $seq === 'B') {
                    $histPos = $seq === 'A' ? max(0, $histPos - 1) : min(count($this->history), $histPos + 1);
                    echo str_repeat("\x08 \x08", mb_strlen($this->buf));
                    $this->buf = $this->history[$histPos] ?? '';
                    echo $this->buf;
                }
                continue;
            }
            if (ord($c) < 32) continue;
            // caractère UTF-8 multi-octets
            $len = ord($c) >= 0xf0 ? 4 : (ord($c) >= 0xe0 ? 3 : (ord($c) >= 0xc0 ? 2 : 1));
            for ($i = 1; $i < $len; $i++) $c .= fread(STDIN, 1);
            $this->buf .= $c;
            echo $c;
        }
    }

    /** Case de l'échiquier sous la position écran (x, y) — 1-indexée —, ou null. */
    private function squareAt(int $x, int $y): ?string
    {
        [$w, $h] = Ui::SIZES[$this->drawnSize];
        $cx = $x - 1 - 3;
        $cy = $y - 1;
        if ($cx < 0 || $cy < 0 || $cx >= 8 * $w || $cy >= 8 * $h) return null;
        $ranks = $this->whiteBottom ? range(8, 1) : range(1, 8);
        $files = $this->whiteBottom ? range(0, 7) : range(7, 0);
        return chr(97 + $files[intdiv($cx, $w)]) . $ranks[intdiv($cy, $h)];
    }

    /** Sélection à la souris : 1er clic = pièce, 2e clic = destination. */
    private function click(string $sq): void
    {
        $legal = $this->state['legal'];
        if ($this->selected !== null) {
            $mv = $this->selected . $sq;
            $this->selected = null;
            if (in_array($mv, $legal, true)) { $this->play($mv); return; }
            if (in_array($mv . 'q', $legal, true)) { $this->play($mv . 'q'); return; }
            if (substr($mv, 0, 2) === $sq) return; // re-clic sur la même case : désélection
        }
        $p = Ui::parseFen($this->state['fen'])[$sq] ?? null;
        $mine = $p !== null && (ctype_upper($p) ? 'w' : 'b') === $this->playerColor;
        if ($mine && array_filter($legal, fn($m) => str_starts_with($m, $sq))) {
            $this->selected = $sq;
        } elseif ($mine) {
            $this->say("Cette pièce ne peut pas bouger.", '2');
        }
    }

    private function helpText(): string
    {
        return implode("\n", [
            'e2e4, e7e8q   jouer un coup',
            'clic          pièce puis case',
            'o-o, o-o-o    roquer',
            'coups [e2]    coups légaux',
            'indice        meilleur coup',
            'annuler       annuler un coup',
            'niveau N      difficulté 1 à 5',
            'taille N      plateau 1 à 3',
            'retourner     retourner le plateau',
            'nouvelle      nouvelle partie',
            'abandon / quitter le jeu',
        ]);
    }

    /** @return bool false pour quitter, true pour relancer une partie */
    public function run(): bool
    {
        $this->say('Tapez un coup (e2e4) ou cliquez une pièce puis sa case. "aide" pour les commandes.', '2');

        while (true) {
            if (($reason = $this->gameOver()) !== null) {
                $this->say($reason, '1;32');
                $this->draw();
                return $this->askReplay();
            }

            if ($this->turn() !== $this->playerColor) {
                $this->say('Stockfish réfléchit…', '2');
                $this->draw();
                Term::$onResume = fn() => $this->draw();
                $this->engineMove();
                Term::drain();
                continue;
            }

            $this->draw();
            $ev = $this->input('Votre coup > ');
            if ($ev[0] === 'eof') return false;
            if ($ev[0] === 'click') {
                $sq = $this->squareAt($ev[1], $ev[2]);
                $sq === null ? $this->selected = null : $this->click($sq);
                continue;
            }
            $this->selected = null;
            $line = trim($ev[1]);
            if ($line === '') continue;
            $cmd = strtolower($line);

            if (in_array($cmd, ['q', 'quit', 'quitter', 'exit'], true)) return false;
            if (in_array($cmd, ['aide', 'help', '?', 'h'], true)) { $this->say($this->helpText()); continue; }
            if (in_array($cmd, ['retourner', 'flip'], true)) { $this->whiteBottom = !$this->whiteBottom; continue; }
            if (in_array($cmd, ['nouvelle', 'new'], true)) return true;
            if (in_array($cmd, ['abandon', 'resign'], true)) {
                $this->say('Vous abandonnez. Stockfish gagne.', '1;31');
                $this->draw();
                return $this->askReplay();
            }
            if (in_array($cmd, ['annuler', 'undo', 'u'], true)) {
                $this->undo();
                continue;
            }
            if (in_array($cmd, ['indice', 'hint'], true)) {
                $c = $this->engine->analyse($this->moves, 14, 1);
                Term::drain();
                $c ? $this->say("Suggestion : {$c[0]['move']}", '1;35') : $this->say('Pas de suggestion.');
                continue;
            }
            if (preg_match('/^(?:coups|moves)(?:\s+([a-h][1-8]))?$/', $cmd, $m)) {
                $list = $this->state['legal'];
                if (!empty($m[1])) $list = array_filter($list, fn($mv) => str_starts_with($mv, $m[1]));
                $this->say('Coups légaux : ' . ($list ? implode(' ', $list) : 'aucun'));
                continue;
            }
            if (preg_match('/^(?:taille|size)\s+([123])$/', $cmd, $m)) {
                self::$size = (int)$m[1];
                continue;
            }
            if (preg_match('/^(?:niveau|level)\s+([1-5])$/', $cmd, $m)) {
                $this->level = (int)$m[1];
                $this->say("Niveau réglé sur {$this->level} — " . LEVELS[$this->level]['name'], '36');
                continue;
            }

            $move = $this->parseMove($cmd);
            if ($move === null) {
                $this->say("Commande inconnue : $line (ex. e2e4, ou \"aide\")", '31');
            } elseif ($move === '') {
                $from = substr($cmd, 0, 2);
                $opts = array_filter($this->state['legal'], fn($mv) => str_starts_with($mv, $from));
                $this->say("Coup illégal : $line", '31');
                if ($opts) $this->say("Depuis $from : " . implode(' ', $opts), '2');
            } else {
                $this->play($move);
            }
        }
    }

    private function undo(): void
    {
        if (count($this->moves) < 2 || ($this->playerColor === 'b' && count($this->moves) < 3)) {
            $this->say('Rien à annuler.');
            return;
        }
        array_splice($this->moves, -2);
        array_splice($this->keys, -2);
        $this->refresh();
        $this->say('Coup annulé.', '36');
    }

    private function askReplay(): bool
    {
        do {
            $ev = $this->input('Nouvelle partie ? [o/N] ');
        } while ($ev[0] === 'click');
        return $ev[0] === 'line' && in_array(strtolower(trim($ev[1])), ['o', 'oui', 'y', 'yes'], true);
    }
}

// ---------------------------------------------------------------------------
// Point d'entrée
// ---------------------------------------------------------------------------
function findStockfish(): string
{
    $candidates = array_filter([
        getenv('STOCKFISH') ?: null,
        __DIR__ . '/stockfish/stockfish-linux-x86-64-universal',
        __DIR__ . '/stockfish/stockfish',
        trim((string)shell_exec('command -v stockfish 2>/dev/null')),
        '/usr/games/stockfish',
    ]);
    foreach ($candidates as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) return $c;
    }
    fwrite(STDERR, "Stockfish introuvable. Définissez la variable STOCKFISH=/chemin/vers/stockfish\n");
    exit(1);
}

function askLevel(): int
{
    echo Ui::c("Choisissez la difficulté :\n", '1');
    foreach (LEVELS as $n => $l) {
        printf("  %d. %-15s %-6s Elo  (voit %d demi-coup%s à l'avance)\n",
            $n, $l['name'], $l['elo'], $l['depth'], $l['depth'] > 1 ? 's' : '');
    }
    while (true) {
        $a = readline('Niveau [1-5, défaut 3] : ');
        if ($a === false) exit(0);
        $a = trim($a);
        if ($a === '') return 3;
        if (preg_match('/^[1-5]$/', $a)) return (int)$a;
    }
}

function askColor(): string
{
    while (true) {
        $a = readline('Couleur [b]lancs / [n]oirs / [h]asard (défaut blancs) : ');
        if ($a === false) exit(0);
        $a = strtolower(trim($a));
        if ($a === '' || $a === 'b' || $a === 'w') return 'w';
        if ($a === 'n') return 'b';
        if ($a === 'h' || $a === 'r') return mt_rand(0, 1) ? 'w' : 'b';
    }
}

$opts = getopt('', ['level:', 'color:', 'size:']);
if (isset($opts['size'])) Game::$size = max(1, min(3, (int)$opts['size']));
$level = isset($opts['level']) ? max(1, min(5, (int)$opts['level'])) : null;
$color = match ($opts['color'] ?? null) {
    'white', 'w', 'blancs' => 'w',
    'black', 'b', 'noirs' => 'b',
    'random' => mt_rand(0, 1) ? 'w' : 'b',
    default => null,
};

$engine = new Engine(findStockfish());
echo "\e[2J\e[H" . Ui::c(' ♞ Chess CLI vs Stockfish ', '1;7') . "\n\n";
$level ??= askLevel();
$color ??= askColor();

register_shutdown_function([Term::class, 'disable']);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP, SIGQUIT] as $sig) pcntl_signal($sig, function () { exit(130); }, false);
    if (function_exists('posix_kill')) pcntl_signal(SIGTSTP, fn() => Term::suspend(), false);
}
// lecture octet par octet : rien ne reste coincé dans le tampon de PHP quand on repasse à readline()
stream_set_read_buffer(STDIN, 0);

while (true) {
    Term::enable();
    $again = (new Game($engine, $level, $color))->run();
    Term::disable();
    if (!$again) break;
    echo "\e[2J\e[H";
    $level = askLevel();
    $color = askColor();
}
echo "À bientôt !\n";
