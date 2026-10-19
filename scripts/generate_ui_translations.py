"""Generate the static Thai-to-English UI dictionary used by the frontend.

Only source-code literals are translated. Runtime customer and business data never
leave the application. Existing translations are retained so reruns are incremental.
"""

from __future__ import annotations

import json
import re
import time
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE_ROOT = ROOT / "frontend" / "src"
BACKEND_ROOT = ROOT / "backend" / "app"
OUTPUT = SOURCE_ROOT / "locales" / "auto-en.json"
THAI = re.compile(r"[ก-๙]")
STRING_LITERAL = re.compile(r"(?s)(?:'([^'\\]*(?:\\.[^'\\]*)*)'|\"([^\"\\]*(?:\\.[^\"\\]*)*)\"|`([^`\\]*(?:\\.[^`\\]*)*)`)")
TAG_TEXT = re.compile(r">([^<>]+)<")
INTERPOLATION = re.compile(r"\$\{[^}]*}|\{\{[^}]*}}")
SPACE = re.compile(r"\s+")


def normalize(value: str) -> str:
    return SPACE.sub(" ", value.replace("\\n", " ").replace("\\t", " ")).strip()


def candidate(value: str) -> bool:
    if not THAI.search(value) or not 2 <= len(value) <= 400:
        return False
    rejected = ("=>", "function ", "const ", " class=", " v-if=", " v-for=", "import ", "selectRaw")
    return not any(token in value for token in rejected)


def source_phrases() -> list[str]:
    phrases: set[str] = set()
    source_files = list(SOURCE_ROOT.rglob("*")) + list(BACKEND_ROOT.rglob("*.php"))
    for path in source_files:
        if path.suffix not in {".vue", ".ts", ".php"} or path.name == "i18n.ts":
            continue
        source = path.read_text(encoding="utf-8")
        values: list[str] = []
        for match in STRING_LITERAL.finditer(source):
            values.append(next(group for group in match.groups() if group is not None))
        values.extend(TAG_TEXT.findall(source))
        for value in values:
            for part in INTERPOLATION.split(value):
                normalized = normalize(part)
                if candidate(normalized):
                    phrases.add(normalized)
                for clause in re.split(r"[|•;]+|\s+[—–]\s+", normalized):
                    clause = normalize(clause)
                    if candidate(clause):
                        phrases.add(clause)
    return sorted(phrases, key=lambda item: (-len(item), item))


def translate(value: str) -> str:
    query = urllib.parse.urlencode({"client": "gtx", "sl": "th", "tl": "en", "dt": "t", "q": value})
    request = urllib.request.Request(
        "https://translate.googleapis.com/translate_a/single?" + query,
        headers={"User-Agent": "Mozilla/5.0 ValorERP-translation-generator"},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.load(response)
    return normalize("".join(part[0] for part in payload[0] if part and part[0]))


def main() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    existing = json.loads(OUTPUT.read_text(encoding="utf-8")) if OUTPUT.exists() else {}
    phrases = source_phrases()
    pending = [phrase for phrase in phrases if phrase not in existing]
    print(f"Found {len(phrases)} phrases; translating {len(pending)} new phrases.")
    def translate_with_retry(phrase: str) -> tuple[str, str]:
        for attempt in range(3):
            try:
                return phrase, translate(phrase)
            except Exception:
                if attempt == 2:
                    raise
                time.sleep(1.5 * (attempt + 1))
        raise RuntimeError(f"Unable to translate: {phrase}")

    with ThreadPoolExecutor(max_workers=10) as executor:
        futures = [executor.submit(translate_with_retry, phrase) for phrase in pending]
        for index, future in enumerate(as_completed(futures), start=1):
            phrase, translation = future.result()
            existing[phrase] = translation
            if index % 25 != 0 and index != len(pending):
                continue
            OUTPUT.write_text(
                json.dumps(dict(sorted(existing.items())), ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            print(f"Translated {index}/{len(pending)}")


if __name__ == "__main__":
    main()
