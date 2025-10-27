
![logo](assets/logo.svg)

# blog_publisher

[![Build status](https://img.shields.io/badge/build-not%20configured-lightgrey.svg)](https://github.com/iamkyno/blog_publisher/actions)
[![Python 3.8+](https://img.shields.io/badge/python-3.8%2B-blue.svg)](https://www.python.org/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A small Python utility to publish blog posts (single-file project).

## What this project is

This repository contains a simple script, `blog_publisher.py`, intended to help automate publishing blog posts. It is a minimal starter project and is suitable for local development and small-scale automation tasks.

## Repository structure

- `blog_publisher.py` — main script
- `requirements.txt` — Python dependencies (if any)
- `README.md` — this file
- `assets/logo.svg` — small project logo used in the README

## Why these icons/badges?

Badges are useful on GitHub because they immediately communicate project status. The included ones are:
- Build status (placeholder) — update once you add CI (GitHub Actions).
- Python version support.
- License (link to `LICENSE` if you add one).

## Requirements

- Python 3.8+ recommended
- pip

Optional: use a virtual environment to isolate dependencies.

## Setup (Windows PowerShell)

Open PowerShell in the project directory and run:

```powershell
# create and activate a venv (recommended)
python -m venv .venv; .\.venv\Scripts\Activate.ps1

# install dependencies
pip install -r requirements.txt
```

If you prefer not to use a venv, install dependencies globally with `pip install -r requirements.txt`.

## Usage

Run the main script:

```powershell
python blog_publisher.py
```

Depending on how `blog_publisher.py` is implemented, it may accept command-line arguments or prompt interactively. Check the top of the file for usage notes or docstrings.

## Development notes

- Keep `requirements.txt` in sync with any added dependencies (use `pip freeze > requirements.txt`).
- Add unit tests under a `tests/` folder and run them with `pytest` (if you add pytest to requirements).

## Next steps (suggested)

- Add a minimal CLI with `argparse` and document commands in this README.
- Add a `tests/` folder and a CI workflow (GitHub Actions) to run tests on push. Update the build badge to point at the workflow when added.
- Add a `LICENSE` (MIT recommended) and a `CONTRIBUTING.md` if you expect external contributions.

## License

Add a license file if you plan to publish this project publicly. If unsure, consider the MIT license.

## Contact

For questions or contributions, open an issue or create a pull request in this repository.
