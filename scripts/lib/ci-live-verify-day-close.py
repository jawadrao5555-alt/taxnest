#!/usr/bin/env python3
"""Classify a private QA response without logging its contents or identity."""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit
import sys


class DayCloseDocument(HTMLParser):
    def __init__(self):
        super().__init__()
        self.login = False
        self.report = False
        self.toggle = False
        self.complete = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            path = urlsplit(attrs.get("action", "")).path.rstrip("/")
            self.login |= path == "/pos/login"
            self.report |= path == "/pos/day-close" and attrs.get("method", "get").lower() == "get"
        if tag == "input":
            self.toggle |= attrs.get("id") == "dc-auto-close-chk" and attrs.get("type", "").lower() == "checkbox"

    def handle_endtag(self, tag):
        self.complete |= tag == "html"


def classify(body):
    document = DayCloseDocument()
    document.feed(body)
    if document.login:
        return "login_document"
    if not document.complete:
        return "incomplete_document"
    if not document.report:
        if "cf-chl-" in body or "challenge-platform" in body:
            return "challenge_document"
        return "unexpected_document"
    if not document.toggle:
        return "day_close_toggle_missing"
    return "ok"


if __name__ == "__main__":
    try:
        print(classify(Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")))
    except (OSError, ValueError, IndexError):
        print("unreadable_document")
        sys.exit(1)
