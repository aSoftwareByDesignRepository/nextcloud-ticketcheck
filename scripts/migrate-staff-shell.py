#!/usr/bin/env python3
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    'templates/projects/detail.php',
    'templates/projects/index.php',
    'templates/projects/form.php',
    'templates/kb/form.php',
    'templates/kb/article.php',
]

STD_OPEN2 = re.compile(
    r"<\?php include __DIR__ \. '(/(?:\.\./)*)common/navigation\.php'; \?>\s*"
    r"(?:<\?php Util::addScript\([^\)]+\); \?>\s*)?"
    r"<a href=\"#main-content\" class=\"helpdesk-skip-link\">.*?</a>\s*"
    r"<div id=\"app-content\" class=\"helpdesk-content\">\s*"
    r"<div id=\"app-content-wrapper\"[^>]*>\s*"
    r"<main id=\"main-content\"[^>]*>\s*",
    re.DOTALL,
)

KB_OPEN = re.compile(
    r"<\?php include __DIR__ \. '(/(?:\.\./)*)common/navigation\.php'; \?>\s*"
    r"<a href=\"#main-content\" class=\"helpdesk-skip-link\">.*?</a>\s*"
    r"(<div id=\"kb-article-data\".*?</div>\s*)"
    r"<div id=\"app-content\" class=\"helpdesk-content\">\s*"
    r"<div id=\"app-content-wrapper\"[^>]*>\s*"
    r"<main id=\"main-content\"[^>]*>\s*",
    re.DOTALL,
)

CLOSE = re.compile(r"\s*</main>\s*</div>\s*</div>(?:\s*|\s*<!--[\s\S]*?-->\s*)*\Z", re.DOTALL)


def main() -> int:
    code = 0
    for rel in FILES:
        path = ROOT / rel
        text = path.read_text()
        if 'page-start.php' in text and 'navigation.php' not in text:
            print('skip', rel)
            continue
        m = re.search(r"include __DIR__ \. '(/(?:\.\./)*)common/navigation", text)
        if not m:
            print('no nav', rel)
            code = 1
            continue
        prefix = m.group(1)
        head = f"<?php include __DIR__ . '{prefix}common/page-start.php'; ?>\n\n"
        tail = f"\n<?php include __DIR__ . '{prefix}common/page-end.php'; ?>\n"
        if 'kb/article.php' in rel:
            new, n = KB_OPEN.subn(lambda mo: head + mo.group(2), text, count=1)
        else:
            new, n = STD_OPEN2.subn(head, text, count=1)
        if n != 1:
            print('open fail', rel)
            code = 1
            continue
        new, nc = CLOSE.subn(tail, new, count=1)
        if nc != 1:
            print('close fail', rel)
            code = 1
            continue
        path.write_text(new)
        print('ok', rel)
    return code


if __name__ == '__main__':
    sys.exit(main())
