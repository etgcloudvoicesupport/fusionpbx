# USF Overview

# How USF Charges Are Calculated

The Universal Service Fund (USF) is a federally mandated fee applied to voice services. The FCC sets a quarterly rate, and we calculate each customer's USF charge based on their services and our actual call traffic.

## How It Works

### 1. We Pull Last Month's Call Data

Every month, we automatically download a detailed call report on the 5th of the month from our carrier (Bandwidth). This report contains every call made across all customers and classifies each one — interstate, local, international, etc.

We use this to determine what percentage of our total call traffic is interstate, since USF only applies to the interstate portion.

### 2. We Calculate Each Customer's USF

Each customer's USF is based on three things:

<table id="bkmrk-input-what-it-is-whe"><thead><tr><th>Input</th><th>What It Is</th><th>Where It Comes From</th></tr></thead><tbody><tr><td>Eligible service costs</td><td>The total monthly cost of the customer's qualifying voice services</td><td>ConnectWise agreement</td></tr><tr><td>Interstate percentage</td><td>The share of all calls that crossed state lines last month</td><td>Bandwidth call report</td></tr><tr><td>Quarterly USF rate</td><td>The FCC-published rate for the current quarter</td><td>Manually updated each quarter</td></tr></tbody></table>

The formula:

> USF Charge = Eligible Service Costs x Interstate % x Quarterly USF Rate

### 3. We Update ConnectWise

Once calculated, each customer's USF line item in ConnectWise is updated with the new amount. Only customers whose USF amount actually changed get updated.

---

## Example

Say a customer pays $500/month in eligible voice services, 4.6% of our calls last month were interstate, and the FCC's quarterly rate is 36.6%:

> $500 x 0.046 x 0.366 = $8.42 USF charge for that month

---

## What Services Count Toward USF?

USF applies to recurring voice service line items like:

- CloudVoice seats
- SIP trunking channels
- Teams voice
- Toll-free service
- Auto attendant, fax, and DID bank lines

The USF fee itself (`CLD-V-USF`) is not included in the calculation.

---

## Timing

<table id="bkmrk-when-what-happens-5t"><thead><tr><th>When</th><th>What Happens</th></tr></thead><tbody><tr><td>5th of each month</td><td>Last month's call data is automatically imported</td></tr><tr><td>After import</td><td>An admin clicks Sync on the USF page to push updated charges to ConnectWise</td></tr><tr><td>Each quarter</td><td>The quarterly USF rate is updated to match the FCC's published rate</td></tr></tbody></table>
