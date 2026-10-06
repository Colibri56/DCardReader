# Install - SOP Builder (macOS and Windows)

This is a Claude Code Agent Skill. Installing it means copying this folder into
your Claude Code skills directory. Once it is there, Claude detects it
automatically whenever you ask to document a process or build an SOP.

There is no required API key and no required integration. The skill works out of
the box on the "universal quartet": a local file, Google Sheets, Gmail, and
Telegram. Connectors are optional add-ons you can wire later.

---

## 1. Requirements

- **Python 3.8 or newer** (3.10+ recommended). Check with:
  - macOS / Linux: `python3 --version`
  - Windows: `py --version`  (or `python --version`)
- **PyYAML** - only needed if you want to run the skill's own validator. The skill
  engine itself uses the standard library only. Install if you want it:
  - macOS / Linux: `python3 -m pip install pyyaml`
  - Windows: `py -m pip install pyyaml`

No other dependencies. The scripts use `pathlib` and standard `urllib`, so the
same code runs identically on macOS and Windows.

---

## 2. Where the skill folder goes

Copy this entire `sop-builder/` folder into one of these locations:

**User-level (available in every project):**
- macOS / Linux: `~/.claude/skills/sop-builder/`
- Windows: `%USERPROFILE%\.claude\skills\sop-builder\`

**Project-level (only inside one project):**
- macOS / Linux: `<your-project>/.claude/skills/sop-builder/`
- Windows: `<your-project>\.claude\skills\sop-builder\`

### Copy commands

**macOS / Linux:**
```bash
mkdir -p ~/.claude/skills
cp -R /path/to/sop-builder ~/.claude/skills/
```

**Windows (PowerShell):**
```powershell
New-Item -ItemType Directory -Force -Path "$env:USERPROFILE\.claude\skills"
Copy-Item -Recurse -Force "C:\path\to\sop-builder" "$env:USERPROFILE\.claude\skills\"
```

After copying, the final layout must be:
```
.../.claude/skills/sop-builder/
  SKILL.md
  references/   onboarding.md  playbook.md  connectors.md
  scripts/      common.py  build_sop.py  scan_repeats.py  detect_connectors.py  notify.py
  config.example.json
  README.md  ADAPT.md  INSTALL.md
  .env.example  .gitignore
```

---

## 3. First run (onboarding)

The first time you ask for an SOP, the skill runs a short, one-time onboarding:
4 warm questions, one at a time. It reads your `context/` and `purpose.md` (if you
have them) to pre-fill, detects which connectors you already have, and writes a
`config.json`. Every later run is instant - it never re-asks.

You do not run anything by hand for this. Just say, in Claude Code, something like:
- `תכין נוהל מהשרשור הזה`
- `write an SOP for how I onboard a new client`
- `/make-sop`

To trigger onboarding, Claude follows `references/onboarding.md`.

---

## 4. Optional - wire a connector

Connectors are never required. To add one:

1. Copy `.env.example` to `.env` in the skill folder.
2. Fill in only the keys for the connector you want (e.g. `TELEGRAM_BOT_TOKEN`).
3. The real `.env` is gitignored and stays on your machine.

Google tools (Gmail / Docs / Sheets / Drive) are usually reached through the
connection already present in your Claude Code session (an MCP server or an OAuth
login), so they typically need no key in `.env`.

---

## 5. Optional - run a script directly (sanity check)

You normally never call the scripts yourself - Claude does. But to confirm the
install works:

**macOS / Linux:**
```bash
cd ~/.claude/skills/sop-builder
python3 scripts/detect_connectors.py
```

**Windows (PowerShell):**
```powershell
cd "$env:USERPROFILE\.claude\skills\sop-builder"
py scripts\detect_connectors.py
```

You should see a JSON report of which connectors are available, with the universal
quartet always present. That confirms the engine runs on your machine.

---

## 6. Uninstall

Delete the `sop-builder/` folder from your `.claude/skills/` directory. Your saved
SOPs (in your `outputs/procedures` folder or your chosen destination) are not
touched.
