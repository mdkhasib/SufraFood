# Sufra GroupOps (Sufra Food Ltd): run guide & demo accounts

Restaurant group operations platform: **Laravel 13 API + web panel** (`backend/`) and a **Flutter Android app** (`mobile/`).
Design: [docs/SYSTEM_PLAN.md](docs/SYSTEM_PLAN.md) · demo company: [docs/DEMO_DATA.md](docs/DEMO_DATA.md)

## Open it

| What | Where |
|---|---|
| Web panel (this PC) | http://localhost:8090 |
| Web panel (phone / other PC on the same Wi-Fi) | http://192.168.110.205:8090 |
| Android app | `sufra-groupops.apk` (all phones) or `sufra-groupops-arm64.apk` (smaller, modern phones) in this folder, or the **📱 Download Android app** button in the footer of every web page. It starts the download and opens the setup guide at http://192.168.110.205:8090/app (server address, examples, QR code). |

In the app, the server address is pre-set to `http://192.168.110.205:8090`. Change it on the login screen (**Server address → Test connection**) if your PC's IP changes. The app adds a missing `http://` and removes `/api` itself. To show phones a fixed address (e.g. a domain), set it in Company settings → Mobile app.

**Password for every demo account: `123456`.** Sign in with the **employee ID** (`E-2008`, `e2008` also works) **or the mobile number** (`01700000038`, `+880 1700-000038` and `8801700000038` all work), on the web and in the app.

## Demo accounts

| Employee ID | Mobile | Person | Role | Best place to test |
|---|---|---|---|---|
| `SA-0001` | 01700000000 | Super Admin (you) | Super Admin | Web: **Modules & features** (module ON/OFF, then each feature OFF / PILOT / ON), **Company settings**, Company admins, Audit log |
| `E-1001` | 01700000001 | Anwar Hossain | Managing Director (MD) | Assign tasks: his list shows the GM, heads and team leads, not waiters. Org chart of the whole company |
| `E-1002` | 01700000002 | Farhana Islam | GM + Company Admin | Web: **Roles & permissions** (one page), Approval rules, Employees, Org chart, Teams |
| `E-1020` | 01700000006 | Nusrat Jahan | HR Manager | Web: Teams, reporting lines, Payroll, Roster, Attendance report, Leave, KPI |
| `E-1010` | 01700000004 | Mahbub Alam | Accounts Manager | Web: Accounting, refunds, vendor bills, salary advances, buyer advances |
| `E-1030` | 01700000008 | Kamrul Hasan | Purchase Manager | Approve purchase requests, assign buyers with a 1-hour deadline |
| `E-1031` | 01700000009 | Rahim Uddin | Buyer | Mobile: Purchases (accept, receipt photo, delivered), Wallet |
| `E-1040` | 01700000012 | Mizanur Rahman | Warehouse Manager | Accept/dispatch requests, raise a purchase for a shortage |
| `E-1050` | 01700000016 | Abdul Karim | CK Head Chef | Accept food orders, web Production plan |
| `E-1060` | 01700000023 | Abul Kalam | Driver | Mobile: Deliveries |
| `E-1070` | 01700000025 | Shirin Akter | Catering Manager | Web: catering events |
| `E-2001` | 01700000031 | Tanjila Ahmed | Branch Manager, Gulshan | Mobile: Team → **Org chart** / Teams, assign to a team, Remind now; Requests, Dashboard |
| `E-2004` | 01700000034 | Shafiq Ahmed | Kitchen Team Lead | Proxy check-in for Rubel and Alamgir; assigns his kitchen line |
| `E-2008` | 01700000038 | Shanto Das | Worker | Mobile: only Today · Tasks · Attendance · Me |
| `E-2011` | 01700000041 | Mitu Akter | Cashier | Web: POS billing, a **DELIVERY** order with the customer's address, then *Cash received* on the Home delivery board |
| `E-1080` | 01790000080 | Sabrina Yasmin | Online Support (orders) | Web: **Home delivery → New delivery order** for any branch (cash on delivery or already paid by bKash/Nagad/card) |
| `E-2019` | 01790000219 | Riaz Uddin | Delivery Rider, Gulshan | Mobile: Tasks → the delivery card (customer, Call, Map, cash to collect) → *Picked up from kitchen* → *Delivered* |

130 people in total (`E-2002`–`E-2018` Gulshan team, `E-3001`… `E-7001` other branch managers).

## Things worth trying

1. **Modules, then features (Super Admin):** Modules & features → switch the *Catering* module OFF → Events disappears from every menu (yours too), the app and the API. Turn it ON again with "also turn all its features ON". A feature cannot be switched on inside an OFF module, and a module cannot go OFF while another module still needs it (e.g. Tasks is needed by KPI).
2. **Company settings:** only the Super Admin sees it at first. On Modules & features → Company settings, press **Allow Company Admins**, and the GM (`E-1002`) gets it. Sections: company profile, tasks & reminders, attendance, **salary advance** (max % of basic, max months, minimum service, one open advance), payroll & KPI, orders/POS/purchase.
3. **Org chart & teams:** web *Team & Org → Org chart* shows who reports to whom (chart or list, search, zoom, focus on one person's tree). Use **+ Task** on any card to give work directly, and **Reports to** to move a person, which their whole team follows; loops are blocked. *Teams* lets you build teams with a lead, a parent team, members and colour, and optionally make the members report to the lead.
4. **Assign list without the crowd:** as the MD (`E-1001`) open *Assign task*. You'll see the GM, department heads, branch managers and team leads, but no waiters. Tasks reach front-line staff through their lead, a team (**team lead shares it out** or **every member gets one**), or the org chart. Company settings can switch this to "everyone below me".
5. **Reminders & done alerts:** when assigning, choose *Top priority*, *remind 30 min before*, *if late remind every 15 min*, and *notify me when finished / started*. Use **Remind now** on a team task. Reminders run every minute from the scheduler.
6. **Roles & permissions:** one page: **Overview** (role × module, e.g. "3/4" or "Full") and **Edit all permissions** (every role × every permission, with tick-all per role, per module and per permission), plus a Roles tab to rename or delete roles or change their scope.
7. **The supply chain:** branch food order → CK → warehouse → purchase (buyer, 1 hour) → back to the branch, as before.
8. **Logo & POS bill:** Company settings → *Logo & bill (POS)*: upload the logo (also used on the web, the app's login and payslips), tagline, bill title, paper 80 mm / 58 mm / A5, font size, 1 or 2 copies, what to print (address, branch phone, VAT/BIN, Bangla names, cashier, customer), footer lines and auto-print. A live preview shows the result; *Open & test print* prints a sample. In POS, **🧾 Print bill** prints the guest bill before payment, and the paid bill shows cash received and change. Branch phones are set in Setup → Locations.
9. **Home delivery:** a cashier (POS → *DELIVERY*) or the online team (`E-1080`, *Home delivery → New delivery order*) places an order for a branch. It shows on that branch's kitchen screen with a 🛵 badge. The branch manager (`E-2001`) opens *Home delivery* and gives it to a rider (staff ticked **Can do home delivery** in Employees: waiters and riders). The rider gets a high-priority task with the customer's name, phone, address, map link, items and the cash to collect. After the kitchen marks it ready, the rider taps *Picked up from kitchen*, then *Delivered* (with the cash collected). The manager or cashier then presses *Cash received*. Each delivery earns the rider that branch's rate (Company settings → *Home delivery*: ON/OFF switch, default ৳ per delivery, a rate per branch, delivery time, who can be a rider, add to payroll). *Delivery report* shows deliveries and bonus per rider, and payroll adds **Home delivery bonus** to the payslip.
10. **Offline POS:** open the POS (with the shift open) while the network works. If the network or the server goes down (power cut), the POS shows a red **OFFLINE** bar and keeps selling: *Send KOT* prints a kitchen ticket, *Pay* prints the bill with an offline number (`OFF-E2011-261124-001`), cash/card/bKash with TxID, and the bills are kept on that computer. When the server is reachable again they sync by themselves (as soon as the connection is back, and every 10 minutes), or press **🔄 Sync**. Synced bills become normal sales in the cashier's shift, the ledger and the stock, at the price charged, and never twice. Company settings → *Orders, POS & purchase* has the ON/OFF switch and the sync minutes. Reopening the POS page while offline needs Chrome on the server PC (`http://localhost:8090`) or HTTPS; over a plain `http://192.168…` address keep the POS tab open (bills are saved either way). Home delivery orders need the network.
11. **Attendance:** GPS **test mode is ON** in the demo. Turn it off in Company settings → Attendance (Super Admin).

## Run it again later

```powershell
cd D:\GroupBusiness\backend; C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe artisan serve --host=0.0.0.0 --port=8090
```
```powershell
cd D:\GroupBusiness\backend; C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe artisan schedule:work
```
The second command runs every minute: task reminders, overdue escalation, recurring checklists, no-show alerts, fixed orders at cut-off, auto check-out and approval SLA. MySQL comes from Laragon (database `groupops`). To reset all demo data:
```powershell
cd D:\GroupBusiness\backend; C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe artisan migrate:fresh --seed --force
```
Tests: `php artisan test` (63 feature tests, 1,328 checks, including opening every page as 13 roles and the full home delivery flow) and `flutter test` in `mobile/`.

## Known limits of this version

- **No push notifications or SMS yet.** Reminders and alerts are in-app (bell and list); the phone app refreshes every minute while open. Push needs a Firebase project, and SMS needs a local gateway account.
- **POS needs the server online.**
- MFS payouts are recorded with a transaction ID; there is no direct bKash/Nagad API integration.
- The APK's screens were tested through the identical Flutter web build. It has **not been run on a real Android device or emulator here**, so please check GPS and the camera on your phone.
