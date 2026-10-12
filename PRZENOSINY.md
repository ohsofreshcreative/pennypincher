# Przeniesienie rozwiązania Figma → blok na inny komputer / inną stronę

Wartości w `<...>` zamień na swoje.

---

## A. Nowa strona na TYM SAMYM komputerze

Skill `figma-block`, `~/.claude/CLAUDE.md` i konektor Figma są globalne, więc działają od razu.

**1. Skopiuj AGENTS.md i utwórz dowiązanie** (w roocie nowego motywu):

    cp /Users/Arek/Websites/h2otwock/app/public/wp-content/themes/h2otwock/AGENTS.md ./AGENTS.md
    ln -s AGENTS.md CLAUDE.md
    ls -la AGENTS.md CLAUDE.md

**2. Sprawdź ID strony w Local** (strona musi być uruchomiona):

    ls ~/Library/Application\ Support/Local/run/

**3. Komenda WP-CLI dla nowej strony:**

    php -d "mysqli.default_socket=$HOME/Library/Application Support/Local/run/<ID>/mysql/mysqld.sock" $(which wp) --path=<ścieżka do>/app/public <komenda>

**4. W Claude Code w nowym motywie napisz:**

> Zaktualizuj w AGENTS.md komendę WP-CLI (ścieżka wp: <which wp>, socket Locala ID: <ID>, ścieżka strony: <...>) i usuń rzeczy specyficzne dla h2otwock.

**5. Wklej link z Figmy.** Skill `figma-block` uruchomi się sam.

---

## B. INNY komputer

### Krok 1. Na obecnym komputerze: spakuj pliki

    mkdir -p ~/Desktop/claude-transfer/memory
    cp -R ~/.claude/skills/figma-block ~/Desktop/claude-transfer/
    cp ~/.claude/CLAUDE.md ~/Desktop/claude-transfer/CLAUDE.md

Pamięć (opcjonalnie):

    M=~/.claude/projects/-Users-Arek-Websites-h2otwock-app-public-wp-content-themes-h2otwock/memory
    cp "$M/figma-mcp-use-figma-only.md" "$M/figma-svg-tlo-w-eksporcie.md" "$M/wp-slash-przy-zapisie-tresci.md" "$M/blok-tlo-przez-select-nie-na-sztywno.md" ~/Desktop/claude-transfer/memory/

Folder `~/Desktop/claude-transfer` przenieś na drugi komputer (AirDrop, pendrive lub chmura).
AGENTS.md przyjdzie razem z repo motywu (git).

### Krok 2. Na nowym komputerze: zainstaluj narzędzia

    brew install node yarn php composer wp-cli
    which wp
    node -v

`which wp` zapamiętaj, `node -v` ma pokazać wersję 20 lub wyższą.
Zainstaluj też Claude Code (claude.com/claude-code) i Local (localwp.com).

### Krok 3. Wgraj skill i ustawienia globalne

    mkdir -p ~/.claude/skills
    cp -R ~/Desktop/claude-transfer/figma-block ~/.claude/skills/
    cp ~/Desktop/claude-transfer/CLAUDE.md ~/.claude/CLAUDE.md

Jeśli `~/.claude/CLAUDE.md` już istnieje, nie nadpisuj go, tylko połącz treści ręcznie.

### Krok 4. Przygotuj motyw

    cd <ścieżka do motywu>/wp-content/themes/<nazwa-motywu>
    ls -la AGENTS.md CLAUDE.md
    [ -e CLAUDE.md ] || ln -s AGENTS.md CLAUDE.md
    composer install
    yarn

### Krok 5. Sprawdź ID strony w Local

Uruchom stronę w Local, potem:

    ls ~/Library/Application\ Support/Local/run/

Komenda WP-CLI:

    php -d "mysqli.default_socket=$HOME/Library/Application Support/Local/run/<ID>/mysql/mysqld.sock" $(which wp) --path=<ścieżka do>/app/public <komenda>

### Krok 6. Pamięć (opcjonalnie)

Najpierw otwórz projekt w Claude Code, żeby powstał jego katalog pamięci. Potem:

    ls ~/.claude/projects
    cp ~/Desktop/claude-transfer/memory/*.md ~/.claude/projects/<katalog-projektu>/memory/

Na koniec napisz Claude'owi: „Dopisz pliki z memory/ do MEMORY.md".

### Krok 7. Figma i start

1. W Claude Code wpisz `/mcp` i zaloguj się do konektora Figma (sprawdź, że jest `use_figma`).
2. Uruchom stronę w Local.
3. Napisz do Claude'a:

> Zaktualizuj w AGENTS.md komendę WP-CLI dla tego komputera (ścieżka wp: <wynik which wp>, socket Locala ID: <ID>, ścieżka strony: <...>) i usuń rzeczy specyficzne dla h2otwock.

4. Wklej link z Figmy. Skill `figma-block` uruchomi się automatycznie.

---

## Co zmieniać w AGENTS.md dla każdej nowej strony

- komenda WP-CLI (ścieżka `wp`, ID Locala, ścieżka strony),
- „Repository map" (liczba bloków, strony opcji ACF, CPT),
- lista „Znane niespójności" (dotyczy tylko h2otwock),
- nazwa motywu i namespace oraz wzorce referencyjne (`Hero.php`, `Values.php`).
