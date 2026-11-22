import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/ui.dart';

/// Read-only management view (owner / GM / branch managers).
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('Dashboard'))),
      body: Loader<Map>(
        load: () async => await Api.get('/dashboard') as Map,
        builder: (context, d, _) {
          final cards = <Widget>[];
          void add(String title, String big, String small, IconData icon, Color c) => cards.add(Card(
                margin: EdgeInsets.zero,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [Icon(icon, size: 18, color: c), const SizedBox(width: 6), Expanded(child: Text(title, style: const TextStyle(fontSize: 12, color: Colors.grey)))]),
                    const Spacer(),
                    FittedBox(child: Text(big, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: c))),
                    Text(small, style: const TextStyle(fontSize: 11), maxLines: 2, overflow: TextOverflow.ellipsis),
                  ]),
                ),
              ));
          final a = d['attendance'];
          if (a != null) add(t('Attendance'), '${a['in']}/${a['rostered']}', '${a['late']} late · ${a['proxy']} proxy · ${a['leave']} leave', Icons.how_to_reg, Colors.green.shade700);
          add(t('Approvals'), '${d['approvals'] ?? 0}', t('pending approvals'), Icons.pending_actions, brand);
          if (d['sales'] != null) add(t('Sales today'), tk(d['sales']['today']), '${d['sales']['orders_today']} orders · yday ${tk(d['sales']['yesterday'])}', Icons.point_of_sale, Colors.blue.shade700);
          if (d['requests'] != null) add(t('Requests'), '${d['requests']['open']}', '${d['requests']['delayed']} delayed · ${d['low_stock']} low stock', Icons.inventory_2, Colors.indigo);
          if (d['tasks'] != null) add(t('Tasks'), '${d['tasks']['overdue']}', 'overdue · ${d['tasks']['done_today']} done today', Icons.checklist, Colors.red.shade700);
          if (d['purchase'] != null) add(t('Purchases'), tk(d['purchase']['spent_today']), 'month ${tk(d['purchase']['spent_month'])}', Icons.shopping_cart, Colors.orange.shade800);
          if (d['money'] != null) {
            final m = d['money'];
            add('Cash', tk((m['CASH'] ?? 0) + (m['DRAWER'] ?? 0) + (m['PETTY'] ?? 0)), 'incl. drawers & petty', Icons.payments, Colors.teal);
            add('Bank · MFS', tk((m['BANK'] ?? 0) + (m['MFS'] ?? 0)), 'Bank ${tk(m['BANK'])} · MFS ${tk(m['MFS'])}', Icons.account_balance, Colors.teal.shade800);
            add('Payables (7 days)', tk(d['payables_week']), 'vendor bills due', Icons.receipt_long, Colors.brown);
          }
          if (d['catering'] != null) add('Catering', '${d['catering']}', 'events this week', Icons.celebration, Colors.pink);
          final byBranch = d['sales']?['by_branch'] as Map?;
          return ListView(padding: const EdgeInsets.all(12), children: [
            GridView.count(crossAxisCount: 2, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(), childAspectRatio: 1.35, mainAxisSpacing: 10, crossAxisSpacing: 10, children: cards),
            if (byBranch != null && byBranch.isNotEmpty) ...[
              const SectionTitle('Sales by branch'),
              for (final e in byBranch.entries) ListTile(dense: true, title: Text('${e.key}'), trailing: Text(tk(e.value), style: const TextStyle(fontWeight: FontWeight.w700))),
            ],
            if (d['tasks']?['by_location'] is Map && (d['tasks']['by_location'] as Map).isNotEmpty) ...[
              const SectionTitle('Overdue tasks by location'),
              for (final e in (d['tasks']['by_location'] as Map).entries) ListTile(dense: true, title: Text('${e.key}'), trailing: Text('${e.value}', style: const TextStyle(color: Colors.red, fontWeight: FontWeight.w700))),
            ],
            const Padding(padding: EdgeInsets.all(12), child: Text('Full reports (POS, accounting, payroll, HR) are on the web panel.', style: TextStyle(color: Colors.grey, fontSize: 12))),
          ]);
        },
      ),
    );
  }
}
