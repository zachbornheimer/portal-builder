#!/usr/bin/env python3
"""Mint identifying PDFs (and tagged MP3s) for the 2027 CFS prod walk."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

from pypdf import PdfReader, PdfWriter
from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.pdfgen import canvas

AUTHOR = "Zach Bornheimer"
EMAIL = "zbornheimer@isjac.org"
AFFIL = "ISJAC"
CREATOR = "Finale 27 — Zach Bornheimer workstation"
PRODUCER = "Adobe PDF Library 17.0 (Zach Bornheimer)"

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / ".artifacts" / "prod-walk-files"
SRC_MP3 = ROOT / "fixtures" / "files" / "sample-recording.mp3"

# Each public file slot gets its own document so Open-to-confirm is obvious.
PDFS: list[tuple[str, str, str]] = [
    ("bio-poster.pdf", "Biography — Poster Sessions", "Field: Bio (poster path)"),
    ("bio-papers.pdf", "Biography — Research Papers", "Field: Bio (papers path)"),
    ("bio-large.pdf", "Biography — Large Ensemble", "Field: Bio (large ensemble)"),
    ("bio-small.pdf", "Biography — Small Ensemble", "Field: Bio (small ensemble)"),
    ("bio-arrangement.pdf", "Biography — Arrangement", "Field: Bio (arrangement)"),
    ("bio-first-takes.pdf", "Biography — First Takes", "Field: Bio (first takes)"),
    ("bio-student.pdf", "Biography — Student / Young Artist", "Field: Bio (student)"),
    ("poster-brief.pdf", "Poster brief — Identifying", "Field: Brief Description"),
    ("paper-abstract.pdf", "Paper abstract — Identifying", "Field: Abstract"),
    ("large-score.pdf", "Large Ensemble score — Identifying", "Field: Score (large)"),
    ("small-score.pdf", "Small Ensemble score — Identifying", "Field: Score (small)"),
    ("arrangement-score.pdf", "Arrangement score — Identifying", "Field: Score (arrangement)"),
    ("first-takes-score.pdf", "First Takes score — Identifying", "Field: Score (first takes)"),
    ("student-score.pdf", "Student score — Identifying", "Field: Score (student)"),
]

MP3S: list[tuple[str, str]] = [
    ("large-rec.mp3", "Large Ensemble recording"),
    ("small-rec.mp3", "Small Ensemble recording"),
    ("arrangement-rec.mp3", "Arrangement recording"),
    ("first-takes-rec.mp3", "First Takes recording"),
    ("student-rec.mp3", "Student / Young Artist recording"),
]


def write_pdf(path: Path, title: str, field_line: str) -> None:
    scratch = path.with_suffix(".scratch.pdf")
    c = canvas.Canvas(str(scratch), pagesize=letter)
    width, height = letter
    c.setTitle(title)
    c.setAuthor(AUTHOR)
    c.setSubject(f"{EMAIL} · {AFFIL} · composer-identifying test")
    c.setCreator(CREATOR)
    c.setKeywords(["Zach Bornheimer", EMAIL, AFFIL, "composer", field_line])

    c.setFillColorRGB(0.72, 0.31, 0.12)
    c.rect(0, height - 1.15 * inch, width, 1.15 * inch, fill=1, stroke=0)
    c.setFillColorRGB(0.99, 0.98, 0.95)
    c.setFont("Times-Bold", 22)
    c.drawString(0.75 * inch, height - 0.55 * inch, title)
    c.setFont("Times-Roman", 12)
    c.drawString(0.75 * inch, height - 0.85 * inch, field_line)

    c.setFillColorRGB(0.07, 0.08, 0.06)
    y = height - 1.8 * inch
    lines = [
        "IDENTIFYING METADATA TEST — do not treat as a real submission body.",
        f"Composer / author: {AUTHOR}",
        f"Email: {EMAIL}",
        f"Institutional affiliation: {AFFIL}",
        "This name, email, and workstation string are also in the PDF Info dict",
        "(Author, Title, Subject, Creator, Producer, Keywords) and should be",
        "stripped by the anonymizer before Open-to-confirm shows the staged file.",
        "",
        "If anonymize worked, this page should no longer name the composer,",
        "and pdfinfo on the staged bytes should not list Zach Bornheimer.",
    ]
    c.setFont("Times-Roman", 13)
    for line in lines:
        c.drawString(0.75 * inch, y, line)
        y -= 18

    c.setFont("Times-Italic", 11)
    c.drawString(0.75 * inch, 0.75 * inch, f"Produced for the 2027 Call for Scores and Papers walk · {AUTHOR}")
    c.save()

    reader = PdfReader(str(scratch))
    writer = PdfWriter()
    writer.append(reader)
    writer.add_metadata(
        {
            "/Author": AUTHOR,
            "/Title": title,
            "/Subject": f"Composer {AUTHOR} <{EMAIL}> {AFFIL}",
            "/Creator": CREATOR,
            "/Producer": PRODUCER,
            "/Keywords": f"{AUTHOR}, {EMAIL}, {AFFIL}, composer",
        }
    )
    with path.open("wb") as fh:
        writer.write(fh)
    scratch.unlink(missing_ok=True)

    info = PdfReader(str(path)).metadata
    if not info or AUTHOR not in str(info.author):
        raise SystemExit(f"metadata missing Author on {path}: {info}")


def write_mp3(path: Path, title: str) -> None:
    if not SRC_MP3.is_file():
        raise SystemExit(f"missing source mp3: {SRC_MP3}")
    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        str(SRC_MP3),
        "-c",
        "copy",
        "-metadata",
        f"artist={AUTHOR}",
        "-metadata",
        f"album_artist={AUTHOR}",
        "-metadata",
        f"title={title}",
        "-metadata",
        f"album=ISJAC 2027 CFS identifying test — {AUTHOR}",
        "-metadata",
        f"comment=composer {AUTHOR} {EMAIL} {AFFIL}",
        str(path),
    ]
    subprocess.run(cmd, check=True, capture_output=True)


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    for name, title, field_line in PDFS:
        dest = OUT / name
        write_pdf(dest, title, field_line)
        print(f"pdf {dest} ({dest.stat().st_size} bytes)")
    for name, title in MP3S:
        dest = OUT / name
        write_mp3(dest, title)
        print(f"mp3 {dest} ({dest.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
