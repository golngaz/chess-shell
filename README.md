# ♞ Chess CLI vs Stockfish

Jeu d'échecs dans le terminal, en PHP, contre le moteur Stockfish.
Les niveaux de difficulté imitent un joueur humain, du débutant à environ 1000 Elo.
On joue au clavier (`e2e4`) ou à la souris, en cliquant sur une pièce puis sur sa case d'arrivée.

> [!WARNING]
> **Projet « vibe codé ».** Ce jeu a été écrit en conversant avec une IA (Claude Code), pour le plaisir, sans démarche d'ingénierie. Il n'a **pas de tests automatisés** : il a été vérifié à la main en jouant, et par des sessions scriptées dans un pseudo-terminal pendant le développement.
> Il a été **testé uniquement sur Konsole** (KDE), sous Linux. Il devrait fonctionner dans d'autres terminaux compatibles xterm, sans garantie.
> À utiliser tel quel. Les contributions et rapports de bugs sont les bienvenus.

```
                      ▗▖              ▗▖      ▗▖                       ♞ Chess vs Stockfish
 8   ▄▗▖▄     ▗▗     ▗▛▟▖   ▗ ▗▖ ▖   ▝▜▛▘    ▗▛▟▖     ▗▗     ▄▗▖▄     Niveau 3/5 — Joueur de club
     ████   ▗▟▛█      ▜▛     ▜██▛    ▜██▛     ▜▛    ▗▟▛█     ████     ~700 Elo · profondeur 3
     ▀▀▀▀    ▝▀▀▘    ▀▀▀▀    ▀▀▀▀   ▝▀▀▀▀▘   ▀▀▀▀    ▝▀▀▘    ▀▀▀▀
                                                                      Stockfish (Noirs)
 7    ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      Vous (Blancs)
      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙      ▟▙
     ▀▀▀▀    ▀▀▀▀    ▀▀▀▀    ▀▀▀▀    ▀▀▀▀    ▀▀▀▀    ▀▀▀▀    ▀▀▀▀     Coups :
```

## Prérequis

- **PHP 8.0 ou plus récent**, en ligne de commande, avec les extensions :
  - `mbstring` et `readline` : obligatoires ;
  - `pcntl` et `posix` : recommandées. Elles permettent de remettre le terminal en état après Ctrl+C, Ctrl+\ ou Ctrl+Z.
- **Stockfish**, à installer séparément (voir plus bas). Le jeu le cherche dans cet ordre :
  1. la variable d'environnement `STOCKFISH` ;
  2. `./stockfish/stockfish-linux-x86-64-universal` ;
  3. `./stockfish/stockfish` ;
  4. `stockfish` dans le `PATH` ;
  5. `/usr/games/stockfish`.
- Un terminal qui gère les **couleurs 256** et les **caractères Unicode « blocs »** (▗▖▟▙…). C'est le cas de Konsole, GNOME Terminal, Kitty, Alacritty, iTerm2 ou Windows Terminal.

Pour vérifier les extensions :

```bash
php -m | grep -E 'mbstring|readline|pcntl|posix'
```

### Installer Stockfish

Stockfish n'est pas inclus dans ce dépôt : son binaire dépasse 100 Mo et il est distribué sous licence GPLv3. Trois possibilités :

- **Avec le gestionnaire de paquets** :

  ```bash
  sudo apt install stockfish      # Debian / Ubuntu (installé dans /usr/games/stockfish)
  sudo dnf install stockfish      # Fedora
  sudo pacman -S stockfish        # Arch
  ```

- **Binaire officiel** : téléchargez-le sur [stockfishchess.org](https://stockfishchess.org/download/) ou depuis les [releases GitHub](https://github.com/official-stockfish/Stockfish/releases). Placez-le ensuite dans `./stockfish/stockfish` et rendez-le exécutable :

  ```bash
  chmod +x stockfish/stockfish
  ```

- **N'importe où** : indiquez son chemin avec `STOCKFISH=/chemin/vers/stockfish`.

## Lancement

```bash
php chess.php
```

Sans option, le jeu demande le niveau puis la couleur. On peut aussi tout passer en ligne de commande :

```bash
php chess.php --level=3 --color=black --size=2
```

Pour utiliser un autre binaire Stockfish :

```bash
STOCKFISH=/chemin/vers/stockfish php chess.php
```

### Options

| Option | Valeurs | Défaut | Rôle |
|---|---|---|---|
| `--level=N` | `1` à `5` | demandé au lancement | Niveau de difficulté (voir plus bas) |
| `--color=C` | `white` / `w` / `blancs`, `black` / `b` / `noirs`, `random` | demandé au lancement | Votre couleur |
| `--size=N` | `1`, `2`, `3` | `3` | Taille maximale du plateau (voir plus bas) |

## Jouer

### Au clavier

Tapez la case de départ puis la case d'arrivée, puis Entrée :

| Saisie | Coup |
|---|---|
| `e2e4` | pion de e2 en e4 |
| `g1f3` | cavalier de g1 en f3 |
| `e7e8q` | promotion en dame (`q`), tour (`r`), fou (`b`) ou cavalier (`n`). Sans lettre, c'est une dame. |
| `o-o` / `0-0` | petit roque |
| `o-o-o` / `0-0-0` | grand roque |

Les espaces, tirets, `x` et `=` sont ignorés : `e2-e4`, `e2 e4`, `e4xd5` ou `e7e8=q` fonctionnent aussi.
Si un coup est illégal, le jeu affiche les coups possibles depuis la case de départ.

La flèche **↑** / **↓** fait défiler les saisies précédentes.

### À la souris

1. **Cliquez sur une de vos pièces.** Sa case passe en vert. Les cases où elle peut aller sont marquées d'un **●**, et en rouge s'il s'agit d'une prise.
2. **Cliquez sur la case d'arrivée** pour jouer le coup.

Recliquer sur la même pièce, ou cliquer en dehors du plateau, annule la sélection.
Une promotion faite à la souris donne toujours une dame. Pour choisir une autre pièce, tapez le coup, par exemple `e7e8n`.

> Tant que le jeu tourne, il capte la souris. Pour sélectionner du texte dans le terminal, maintenez **Shift** pendant le glisser.

### Commandes

| Commande | Alias | Effet |
|---|---|---|
| `aide` | `help`, `?`, `h` | Afficher l'aide |
| `coups` | `moves` | Lister tous les coups légaux |
| `coups e2` | `moves e2` | Lister les coups légaux depuis une case |
| `indice` | `hint` | Demander à Stockfish le meilleur coup (calcul en profondeur 14) |
| `annuler` | `undo`, `u` | Annuler votre dernier coup et la réponse de Stockfish |
| `niveau N` | `level N` | Changer la difficulté en cours de partie (1 à 5) |
| `taille N` | `size N` | Changer la taille du plateau (1 à 3) |
| `retourner` | `flip` | Retourner l'échiquier |
| `nouvelle` | `new` | Nouvelle partie : le jeu redemande le niveau et la couleur |
| `abandon` | `resign` | Abandonner la partie |
| `quitter` | `q`, `quit`, `exit` | Quitter le jeu (Ctrl+D aussi) |

## Niveaux de difficulté

L'IA ne joue pas toujours le meilleur coup de Stockfish. Pour imiter un joueur humain, elle :

- ne calcule qu'à une **profondeur limitée**, en demi-coups ;
- regarde **plusieurs coups candidats** et en choisit un au hasard, en favorisant les meilleurs ;
- fait parfois une **gaffe**, c'est-à-dire un coup légal tiré au hasard.

Elle joue cependant toujours un mat quand elle en voit un.

| Niveau | Nom | Elo visé | Profondeur | Candidats | Écart toléré | Gaffes |
|---|---|---|---|---|---|---|
| 1 | Débutant | ~400 | 1 | 12 | 4 pions | 20 % |
| 2 | Novice | ~550 | 2 | 8 | 2,5 pions | 12 % |
| 3 | Joueur de club | ~700 | 3 | 6 | 1,6 pion | 7 % |
| 4 | Intermédiaire | ~850 | 4 | 5 | 1 pion | 4 % |
| 5 | Confirmé | ~1000 | 6 | 4 | 0,6 pion | 2 % |

Les valeurs Elo sont des estimations. Pour ajuster un niveau, modifiez le tableau `LEVELS` en haut de `chess.php`. Ses champs :

| Champ | Rôle |
|---|---|
| `depth` | profondeur de calcul |
| `multipv` | nombre de coups candidats |
| `margin` | écart maximal toléré avec le meilleur coup, en centipions |
| `temp` | part de hasard dans le choix : plus la valeur est haute, plus c'est aléatoire |
| `blunder` | probabilité de jouer un coup au hasard |

## Taille du plateau

| Taille | Case | Hauteur du plateau | Pièces |
|---|---|---|---|
| `1` | 3 × 1 caractères | 9 lignes | symboles Unicode (♚ ♛ ♜…) |
| `2` | 7 × 3 caractères | 25 lignes | pixel art moyen |
| `3` | 8 × 4 caractères | 33 lignes | pixel art grand, avec un pixel de marge |

La taille demandée est un **maximum**. À chaque affichage, le jeu mesure le terminal et prend la plus grande taille qui tient en hauteur. Le camp adverse ne sort ainsi jamais de l'écran. Si le terminal est trop petit, un message le signale une fois. Il suffit d'agrandir la fenêtre ou de dézoomer (`Ctrl` + `-`) pour retrouver la grande taille.

Le panneau d'informations (niveau, pièces prises, historique, messages) s'affiche à droite du plateau si la largeur le permet, sinon en dessous.

## Affichage

- **Dernier coup** : les cases de départ et d'arrivée sont surlignées.
- **Échec** : la case du roi passe en rouge et « Échec ! » s'affiche.
- **Roi** : il porte une croix rouge pour être repéré d'un coup d'œil. Elle devient jaune quand il est en échec. En taille 1, le roi est souligné en rouge.
- **Matériel** : le panneau montre les pièces prises par chaque camp et l'avantage en points (dame 9, tour 5, fou et cavalier 3, pion 1).

## Fin de partie

Le jeu détecte :

- l'**échec et mat** ;
- le **pat** ;
- la **règle des 50 coups** ;
- la **triple répétition** ;
- le **matériel insuffisant** : roi seul, roi et une pièce mineure, ou uniquement des fous sur des cases de même couleur.

À la fin d'une partie, le jeu propose d'en lancer une nouvelle.

## Dépannage

| Problème | Solution |
|---|---|
| Le plateau est petit ou les pièces simplifiées | Le terminal n'est pas assez haut. Agrandissez la fenêtre ou dézoomez : il faut 34 lignes pour la taille 3 et 26 pour la taille 2. |
| Les pièces s'affichent comme des carrés ou des `?` | La police ne contient pas les caractères « blocs ». Changez de police (DejaVu Sans Mono, JetBrains Mono, Fira Code…) ou utilisez `taille 1`. |
| Les couleurs sont bizarres | Le terminal doit gérer les 256 couleurs. Vérifiez que `TERM` vaut par exemple `xterm-256color`. |
| Les clics ne font rien | Le terminal ou le multiplexeur ne transmet pas la souris. Dans tmux, activez `set -g mouse on`. Le clavier fonctionne toujours. |
| `Stockfish introuvable` | Définissez `STOCKFISH=/chemin/vers/stockfish`, ou installez le paquet `stockfish`. |

## Créer une release

Le script `release.sh` produit un zip autonome qui embarque tout :

- le jeu ;
- la **dernière version de Stockfish** pour Linux x86-64, téléchargée à la volée depuis les [releases officielles](https://github.com/official-stockfish/Stockfish/releases), avec ses sources et sa licence GPLv3 ;
- un lanceur `chess-shell`.

```bash
./release.sh 1.0.0
```

Le seul paramètre est le numéro de version. Un éventuel `v` en tête est retiré. Le script produit dans `release/`, un dossier ignoré par git :

- `chess-shell-<version>-linux-x86-64.zip`, d'environ 80 Mo ;
- `chess-shell-<version>-linux-x86-64.zip.sha256`, sa somme de contrôle.

Pour construire la release, il faut `curl`, `tar`, `zip`, `php` et `sha256sum`. Si `chess.php`, `README.md` ou `LICENSE` ont des modifications non commitées, le script prévient mais les inclut.

Côté utilisateur, seul PHP est nécessaire :

```bash
unzip chess-shell-1.0.0-linux-x86-64.zip
./chess-shell-1.0.0-linux-x86-64/chess-shell --level=3
```

## Licence

Ce projet est distribué sous licence [MIT](LICENSE).

[Stockfish](https://stockfishchess.org/) est un programme distinct, sous licence [GPLv3](https://github.com/official-stockfish/Stockfish/blob/master/Copying.txt). Il n'est pas inclus dans ce dépôt : le jeu le lance comme un processus séparé et dialogue avec lui via le protocole UCI.
