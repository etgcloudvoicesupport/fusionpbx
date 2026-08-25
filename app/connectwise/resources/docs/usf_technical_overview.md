# ConnectWise USF Calculator - Technical Overview

## Overview

The USF (Universal Service Fund) workflow has two parts:

1. A cron job that imports call data from Bandwidth
2. A web page that calculates and syncs USF charges to ConnectWise

---

## Step 1: BDR Import (Automated)

Script: `app/connectwise/resources/jobs/bandwidth_bdr_import.php` Schedule: Cron runs at midnight on the 5th of every month

### What it does:

1. Requests a Voice BDR (Billing Detail Record) from the Bandwidth Insights API for the previous month
2. Downloads and unzips the report CSV to `/tmp/bdr_reports/`
3. Loads all call records into a temporary database table
4. Aggregates call minutes by type: 
    - Interstate — calls between states
    - Intrastate — calls within a state
    - International
    - Local — local origination
    - Toll-Free — toll-free origination
    - Other Local — information/411, WVO, outbound toll-free
    - USF Minutes — interstate + international (the federally assessable portion)
    - Total Minutes
5. Saves the aggregated stats to the `v_bdr_stats` table (one row per month)

### Manual usage:

# Import last month (default)

php /var/www/fusionpbx/app/connectwise/resources/jobs/bandwidth\_bdr\_import.php

# Import a specific month

php /var/www/fusionpbx/app/connectwise/resources/jobs/bandwidth\_bdr\_import.php year=2026 month=3

---

## Step 2: USF Calculation &amp; Sync (Manual)

Script: `app/connectwise/connectwise_usf.php` Triggered by: Clicking Sync on the USF web page

### The formula:

New USF = Eligible Costs × Interstate Percent × Quarterly USF Rate

Where:

- Eligible Costs = sum of `extPrice` for all USF-eligible additions on a customer's agreement
- Interstate Percent = interstate minutes ÷ total minutes (from BDR stats)
- Quarterly USF Rate = FCC-published rate, stored in session settings (`quarterly_usf`)

### What it does:

1. Reads last month's BDR stats from `v_bdr_stats`
2. Calculates the interstate percentage (shared across all customers)
3. For each customer agreement: 
    - Fetches additions from ConnectWise
    - Sums the costs of USF-eligible products
    - Calculates the new USF amount
    - If the amount changed, updates the `CLD-V-USF` addition's `unitPrice` and `unitCost` in ConnectWise

---

## USF-Eligible Products

These ConnectWise product identifiers are included in the eligible cost calculation:

<table id="bkmrk-product-id-descripti"><thead><tr><th>Product ID</th><th>Description</th></tr></thead><tbody><tr><td>`CldV-Basic-Serv`</td><td>CloudVoice Basic Service</td></tr><tr><td>`CldV-Essen-Serv`</td><td>CloudVoice Essential Service</td></tr><tr><td>`CldV-Enter-Serv`</td><td>CloudVoice Enterprise Service</td></tr><tr><td>`CLD-V-ESS-SRV`</td><td>CloudVoice Essential Service</td></tr><tr><td>`CLD-V-ENT-SRV`</td><td>CloudVoice Enterprise Service</td></tr><tr><td>`CLD-V-ENT-SRV-UPL`</td><td>CloudVoice Enterprise Service (Upgrade)</td></tr><tr><td>`CSICLD-V-ENT-SRV`</td><td>CSI CloudVoice Enterprise Service</td></tr><tr><td>`CldV-3yr-Basic-Serv`</td><td>CloudVoice 3-Year Basic</td></tr><tr><td>`CldV-3yr-Enter-Serv`</td><td>CloudVoice 3-Year Enterprise</td></tr><tr><td>`CldV-3yr-Essen-Serv`</td><td>CloudVoice 3-Year Essential</td></tr><tr><td>`CldV-3yr-UC-Serv`</td><td>CloudVoice 3-Year UC</td></tr><tr><td>`CldV-3yr-UC+-Serv`</td><td>CloudVoice 3-Year UC+</td></tr><tr><td>`CldV-5yr-Basic-Serv`</td><td>CloudVoice 5-Year Basic</td></tr><tr><td>`CldV-5yr-Enter-Serv`</td><td>CloudVoice 5-Year Enterprise</td></tr><tr><td>`CLD-V-CAS-SRV`</td><td>CloudVoice CAS Service</td></tr><tr><td>`CldV-CCAPrem-Serv`</td><td>CloudVoice CCA Premium</td></tr><tr><td>`CldV-CCAStd-Serv`</td><td>CloudVoice CCA Standard</td></tr><tr><td>`CLD-V-ILD`</td><td>International Long Distance</td></tr><tr><td>`CLDV-LITE-SRV`</td><td>CloudVoice Lite Service</td></tr><tr><td>`CldV-LocalTel-Serv`</td><td>CloudVoice Local Tel Service</td></tr><tr><td>`CLD-Voice`</td><td>CloudVoice</td></tr><tr><td>`CLDV-SIPT-Channel`</td><td>SIP Trunking Channel</td></tr><tr><td>`CLD-V-TEAMS`</td><td>CloudVoice Teams</td></tr><tr><td>`CLD-V-TollFree`</td><td>Toll-Free Service</td></tr><tr><td>`CLD-V-VE`</td><td>CloudVoice VE</td></tr><tr><td>`CLD-V-VP`</td><td>CloudVoice VP</td></tr><tr><td>`CldV-FTF-Serv`</td><td>CloudVoice FTF Service</td></tr><tr><td>`CLD-V-DID-Bank`</td><td>DID Bank</td></tr><tr><td>`CldV-AutoAtt-Serv`</td><td>Auto Attendant Service</td></tr><tr><td>`CldV-Fax-Serv`</td><td>Fax Service</td></tr><tr><td>`CLD-V-ATT-SRV`</td><td>Attendant Service</td></tr></tbody></table>

> Note: The USF line item itself (`CLD-V-USF`) is explicitly excluded from the eligible cost calculation.

---

## Key Files

<table id="bkmrk-file-purpose-resourc"><thead><tr><th>File</th><th>Purpose</th></tr></thead><tbody><tr><td>`resources/jobs/bandwidth_bdr_import.php`</td><td>Cron job — imports BDR from Bandwidth</td></tr><tr><td>`connectwise_usf.php`</td><td>Web UI — calculates and syncs USF to ConnectWise</td></tr><tr><td>`resources/classes/connectwise.php`</td><td>ConnectWise API client and product ID list</td></tr><tr><td>`connectwise_quarterly.php`</td><td>Quarterly USF history view</td></tr></tbody></table>

---

## Database

<table id="bkmrk-table-purpose-v_bdr_"><thead><tr><th>Table</th><th>Purpose</th></tr></thead><tbody><tr><td>`v_bdr_stats`</td><td>Stores monthly aggregated call minute stats</td></tr><tr><td>`v_connectwise_agreements`</td><td>Maps FusionPBX domains to ConnectWise agreement IDs</td></tr></tbody></table>
