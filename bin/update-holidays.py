#!/usr/bin/env python3
"""Regenerate i18n/holidays.php from the OpenHolidays API.

Fetches the nationwide, full-day public holidays for the current and the next
year and writes them to a PHP file returning an array of ISO-8601 dates keyed
by country code. The generated file is only replaced once every single value
has been validated, so a partial or malformed API response can never corrupt
the existing data.

Usage:
    python3 bin/update-holidays.py [--insecure] [--output PATH] [--years N]
"""

import argparse
import datetime
import json
import os
import re
import ssl
import sys
import tempfile
import time
import urllib.error
import urllib.request

API_BASE = "https://openholidaysapi.org"
USER_AGENT = "shiptastic-for-woocommerce holiday updater"

# A country key must be a plain ISO 3166-1 alpha-2 code, a holiday must be an
# ISO-8601 date. Everything written to the PHP file is matched against these.
COUNTRY_RE = re.compile(r"^[A-Z]{2}$")
DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")

REQUEST_TIMEOUT = 30
REQUEST_RETRIES = 3
REQUEST_BACKOFF = 2

# Refuse to write a file that looks obviously broken.
MIN_COUNTRIES = 20
MAX_SPAN_DAYS = 366
MAX_MISSING_RATIO = 0.2


def log(message):
    print(message, file=sys.stderr)


class UpdateError(Exception):
    """Raised whenever the run must be aborted without touching the output file."""


def find_ca_bundle():
    """Locate a CA bundle for Python builds that ship without one (e.g. macOS)."""
    paths = ssl.get_default_verify_paths()

    if paths.cafile or (paths.capath and os.path.isdir(paths.capath)):
        return None

    try:
        import certifi

        return certifi.where()
    except ImportError:
        pass

    for candidate in ("/etc/ssl/cert.pem", "/opt/homebrew/etc/ca-certificates/cert.pem"):
        if os.path.isfile(candidate):
            return candidate

    return None


def build_ssl_context(insecure):
    if insecure:
        log("WARNING: TLS certificate verification is disabled (--insecure).")
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

        return ctx

    return ssl.create_default_context(cafile=find_ca_bundle())


def fetch_json(url, ctx):
    """GET a URL and return the decoded JSON list, retrying on transient errors."""
    last_error = None

    for attempt in range(1, REQUEST_RETRIES + 1):
        request = urllib.request.Request(
            url, headers={"Accept": "application/json", "User-Agent": USER_AGENT}
        )

        try:
            with urllib.request.urlopen(request, context=ctx, timeout=REQUEST_TIMEOUT) as response:
                if response.status != 200:
                    raise UpdateError("Unexpected HTTP status %s for %s" % (response.status, url))

                payload = response.read()
        except (urllib.error.URLError, TimeoutError, ssl.SSLError) as error:
            last_error = error
        else:
            try:
                data = json.loads(payload)
            except (json.JSONDecodeError, UnicodeDecodeError) as error:
                raise UpdateError("Invalid JSON returned by %s: %s" % (url, error))

            if not isinstance(data, list):
                raise UpdateError("Expected a JSON list from %s, got %s" % (url, type(data).__name__))

            return data

        if attempt < REQUEST_RETRIES:
            delay = REQUEST_BACKOFF ** attempt
            log("Request to %s failed (%s), retrying in %ss..." % (url, last_error, delay))
            time.sleep(delay)

    raise UpdateError("Request to %s failed after %s attempts: %s" % (url, REQUEST_RETRIES, last_error))


def fetch_countries(ctx):
    data = fetch_json(API_BASE + "/Countries", ctx)
    countries = []

    for country in data:
        if not isinstance(country, dict):
            continue

        iso_code = country.get("isoCode")

        if not isinstance(iso_code, str) or not COUNTRY_RE.match(iso_code):
            log("Skipping country with unexpected ISO code: %r" % (iso_code,))
            continue

        if iso_code not in countries:
            countries.append(iso_code)

    if len(countries) < MIN_COUNTRIES:
        raise UpdateError("Only %s valid countries returned by the API, aborting." % len(countries))

    return sorted(countries)


def parse_date(value, context):
    """Validate an API date against DATE_RE and return it as a date object."""
    if not isinstance(value, str) or not DATE_RE.match(value):
        log("Skipping %s: %r is not an ISO-8601 date." % (context, value))
        return None

    try:
        return datetime.date.fromisoformat(value)
    except ValueError:
        log("Skipping %s: %r is not a valid date." % (context, value))
        return None


def collect_holidays(country, year, ctx):
    """Return the list of full-day, nationwide holiday dates of a country and year."""
    url = "%s/PublicHolidays?countryIsoCode=%s&validFrom=%s-01-01&validTo=%s-12-31" % (
        API_BASE,
        country,
        year,
        year,
    )

    dates = []

    for holiday in fetch_json(url, ctx):
        if not isinstance(holiday, dict):
            continue

        if holiday.get("temporalScope") != "FullDay" or not holiday.get("nationwide"):
            continue

        context = "holiday of %s (%s)" % (country, year)
        start = parse_date(holiday.get("startDate"), context)
        end = parse_date(holiday.get("endDate"), context)

        if start is None or end is None:
            continue

        if end < start:
            log("Skipping %s: end date %s precedes start date %s." % (context, end, start))
            continue

        span = (end - start).days

        if span > MAX_SPAN_DAYS:
            log("Skipping %s: spans %s days." % (context, span))
            continue

        for offset in range(span + 1):
            dates.append(start + datetime.timedelta(days=offset))

    return dates


def build_holidays(countries, years, ctx):
    holidays = {}

    for country in countries:
        dates = []

        for year in years:
            dates.extend(collect_holidays(country, year, ctx))

        # Deduplicate overlapping multi-day holidays and keep a stable order.
        formatted = sorted({date.isoformat() for date in dates})

        if not formatted:
            log("No holidays found for %s, skipping." % country)
            continue

        holidays[country] = formatted

    if len(holidays) < MIN_COUNTRIES:
        raise UpdateError("Only %s countries with holidays collected, aborting." % len(holidays))

    return holidays


def validate(holidays):
    """Final gate: every key and every value must match the expected pattern."""
    for country, dates in holidays.items():
        if not COUNTRY_RE.match(country):
            raise UpdateError("Invalid country code in result: %r" % (country,))

        if not dates:
            raise UpdateError("No holidays collected for %s." % country)

        for date in dates:
            if not DATE_RE.match(date):
                raise UpdateError("Invalid holiday date for %s: %r" % (country, date))


def check_regression(path, holidays, force):
    """Abort if the new result drops a significant share of the countries we already had."""
    if force or not os.path.isfile(path):
        return

    try:
        with open(path, "r", encoding="utf-8") as existing_file:
            previous = set(re.findall(r"^\t'([A-Z]{2})' => array\(", existing_file.read(), re.MULTILINE))
    except OSError as error:
        log("Could not read the existing %s (%s), skipping the regression check." % (path, error))
        return

    if not previous:
        return

    missing = sorted(previous - set(holidays))

    if not missing:
        return

    log("Countries present in %s but missing now: %s" % (path, ", ".join(missing)))

    if len(missing) / len(previous) > MAX_MISSING_RATIO:
        raise UpdateError(
            "%s of %s known countries disappeared, refusing to overwrite. Use --force to override."
            % (len(missing), len(previous))
        )


def render(holidays):
    lines = [
        "<?php",
        "/**",
        " * Holidays",
        " *",
        " * Returns an array of holidays.",
        " *",
        " * @version 1.0.0",
        " */",
        "defined( 'ABSPATH' ) || exit;",
        "",
        "return array(",
    ]

    for country, dates in holidays.items():
        lines.append("\t'%s' => array(" % country)
        lines.extend("\t\t'%s'," % date for date in dates)
        lines.append("\t),")

    lines.append(");")

    return "\n".join(lines) + "\n"


def write_atomic(path, contents):
    """Write via a temp file in the target directory so the output is never half-written."""
    directory = os.path.dirname(path) or "."

    if not os.path.isdir(directory):
        raise UpdateError("Output directory does not exist: %s" % directory)

    handle, temp_path = tempfile.mkstemp(dir=directory, prefix=".holidays-", suffix=".php")

    try:
        with os.fdopen(handle, "w", encoding="utf-8", newline="\n") as temp_file:
            temp_file.write(contents)

        os.chmod(temp_path, 0o644)
        os.replace(temp_path, path)
    except Exception:
        if os.path.exists(temp_path):
            os.unlink(temp_path)

        raise


def main():
    default_output = os.path.join(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "i18n", "holidays.php"
    )

    parser = argparse.ArgumentParser(description="Regenerate i18n/holidays.php from the OpenHolidays API.")
    parser.add_argument("--output", default=default_output, help="Path of the generated PHP file.")
    parser.add_argument("--years", type=int, default=2, help="Number of years to fetch, starting with the current one.")
    parser.add_argument("--insecure", action="store_true", help="Disable TLS certificate verification.")
    parser.add_argument("--force", action="store_true", help="Write the file even if known countries disappeared.")
    args = parser.parse_args()

    if args.years < 1:
        parser.error("--years must be at least 1")

    ctx = build_ssl_context(args.insecure)
    current_year = datetime.date.today().year
    years = list(range(current_year, current_year + args.years))

    try:
        countries = fetch_countries(ctx)
        log("Fetching holidays for %s countries and years %s..." % (len(countries), ", ".join(map(str, years))))

        holidays = build_holidays(countries, years, ctx)
        validate(holidays)
        check_regression(args.output, holidays, args.force)
        write_atomic(args.output, render(holidays))
    except UpdateError as error:
        log("Error: %s" % error)
        return 1

    total = sum(len(dates) for dates in holidays.values())
    log("Wrote %s holidays for %s countries to %s." % (total, len(holidays), args.output))

    return 0


if __name__ == "__main__":
    sys.exit(main())
