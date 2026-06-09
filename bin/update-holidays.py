import urllib.request, json, datetime, ssl

countries = []
now      = datetime.date.today()
years    = [ now.year, now.year + 1 ]
holidays = {}

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

url = "https://openholidaysapi.org/Countries"

with urllib.request.urlopen(url, context=ctx) as response:
    data = json.loads(response.read())

    for countryData in data:
        countries.append(countryData['isoCode'])

for country in countries:
    if country not in holidays:
        holidays[country] = []

    for year in years:
        url = "https://openholidaysapi.org/PublicHolidays?countryIsoCode=" + str(country) + "&validFrom=" + str(year) + "-01-01&validTo=" + str(year) + "-12-31"

        with urllib.request.urlopen(url, context=ctx) as response:
            data = json.loads(response.read())

            for holidayMeta in data:
                if "FullDay" == holidayMeta["temporalScope"] and holidayMeta["nationwide"]:
                    start = datetime.datetime.strptime(holidayMeta["startDate"], "%Y-%m-%d")
                    end = datetime.datetime.strptime(holidayMeta["endDate"], "%Y-%m-%d")

                    delta = end - start

                    for i in range(delta.days + 1):
                        day = start + datetime.timedelta(days=i)
                        holidays[country].append(day.strftime("%Y-%m-%d"))

with open( "i18n/holidays.php", "w" ) as holiday_file:
    holiday_array = "\nreturn array("

    for country, holidays in holidays.items():
        holiday_array = holiday_array + "\n\t'" + str(country) + "' => array(\n"

        for holiday in holidays:
            holiday_array = holiday_array + "\t\t'" + holiday + "'" + ",\n"

        holiday_array = holiday_array + "\t),"

    holiday_array = holiday_array + "\n);"

    holiday_file.write("""<?php
/**
 * Holidays
 *
 * Returns an array of holidays.
 *
 * @version 1.0.0
 */
defined( 'ABSPATH' ) || exit;
""" + holiday_array + "\n" )
    holiday_file.close()