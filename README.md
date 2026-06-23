
# Bitcoin Price JSON

Daily Bitcoin (BTC/USD) historical price data in JSON format.

## Features

- Daily BTC/USD prices
- One JSON file per year
- Automatically updated daily
- Historical data from 2010 onwards

## JSON Format

```json
{
    "2026-01-01": 88876.25,
    "2026-01-02": 89993.24,
    "2026-01-03": 90604.78
}
```

## Usage

Example URL:

```text
https://raw.githubusercontent.com/viratsoft/btc-price-json/main/json/2026.json
```

## Available Years

- 2010 → `json/2010.json`
- 2011 → `json/2011.json`
- 2012 → `json/2012.json`
- 2013 → `json/2013.json`
- 2014 → `json/2014.json`
- 2015 → `json/2015.json`
- 2016 → `json/2016.json`
- 2017 → `json/2017.json`
- 2018 → `json/2018.json`
- 2019 → `json/2019.json`
- 2020 → `json/2020.json`
- 2021 → `json/2021.json`
- 2022 → `json/2022.json`
- 2023 → `json/2023.json`
- 2024 → `json/2024.json`
- 2025 → `json/2025.json`
- 2026 → `json/2026.json`
- .....

New yearly JSON files will be created automatically when new data becomes available.

## Update Schedule

- Daily at 01:30 UTC
- Daily at 07:00 AM IST

## Data Source

CoinGecko Bitcoin market data API.

## License

MIT License
