import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';
import 'shell.dart';

class MeScreen extends StatelessWidget {
  final List<NavTab> extraTabs;
  const MeScreen({super.key, this.extraTabs = const []});

  @override
  Widget build(BuildContext context) {
    final s = Session.I;
    final me = s.me ?? {};
    void open(Widget w) => Navigator.push(context, MaterialPageRoute(builder: (_) => w));
    return Scaffold(
      appBar: AppBar(title: Text(extraTabs.isEmpty ? t('Me') : t('More'))),
      body: ListView(children: [
        Card(
          margin: const EdgeInsets.all(12),
          child: ListTile(
            leading: CircleAvatar(backgroundColor: brand, child: Text(s.name.isNotEmpty ? s.name[0] : '?', style: const TextStyle(color: Colors.white))),
            title: Text(s.name, style: const TextStyle(fontWeight: FontWeight.w800)),
            subtitle: Text('${me['employee_code']} · ${me['position'] ?? ''}\n${me['role'] ?? ''} · ${me['location']?['name'] ?? ''}'),
            isThreeLine: true,
          ),
        ),
        for (final tb in extraTabs)
          ListTile(leading: Icon(tb.icon), title: Text(t(tb.label)), trailing: const Icon(Icons.chevron_right), onTap: () => open(tb.page())),
        if (extraTabs.isNotEmpty) const Divider(),
        ListTile(leading: const Icon(Icons.notifications_outlined), title: Text(t('Notifications')), onTap: () => open(const NotificationsScreen())),
        if (s.can('kpi.view_own')) ListTile(leading: const Icon(Icons.star_outline), title: Text(t('My KPI')), onTap: () => open(const KpiScreen())),
        if (s.can('payroll.view_own')) ListTile(leading: const Icon(Icons.receipt_long), title: Text(t('My payslips')), onTap: () => open(const PayslipsScreen())),
        if (s.can('payroll.advance_request')) ListTile(leading: const Icon(Icons.payments_outlined), title: Text(t('Salary advance')), onTap: () => _advance(context)),
        ListTile(
          leading: const Icon(Icons.translate),
          title: Text(t('Language')),
          trailing: SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'en', label: Text('EN')), ButtonSegment(value: 'bn', label: Text('বাং'))],
            selected: {s.lang},
            onSelectionChanged: (v) => s.setLang(v.first),
          ),
        ),
        ListTile(leading: const Icon(Icons.dns_outlined), title: Text(t('Server address')), subtitle: Text(Api.base)),
        ListTile(
          leading: const Icon(Icons.logout, color: Colors.red),
          title: Text(t('Logout'), style: const TextStyle(color: Colors.red)),
          onTap: () => s.logout(),
        ),
        const Padding(padding: EdgeInsets.all(16), child: Text('Sufra GroupOps · POS, accounting, payroll & setup are on the web panel.', style: TextStyle(color: Colors.grey, fontSize: 12))),
      ]),
    );
  }

  Future<void> _advance(BuildContext context) async {
    final amount = TextEditingController();
    final months = TextEditingController(text: '3');
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(t('Salary advance')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: '${t('Amount')} ৳')),
          TextField(controller: months, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Repay in months')),
          TextField(controller: reason, decoration: InputDecoration(labelText: t('Reason'))),
        ]),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(t('Cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t('Send')))],
      ),
    );
    if (ok == true && context.mounted) {
      await run(context, () => Api.post('/salary-advances', {'amount': amount.text, 'months': int.tryParse(months.text) ?? 3, 'reason': reason.text}));
    }
  }
}

class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  static const icons = {'warning': Icons.warning_amber, 'success': Icons.check_circle, 'approval': Icons.pending_actions, 'task': Icons.checklist, 'attendance': Icons.access_time, 'delivery': Icons.local_shipping, 'purchase': Icons.shopping_cart, 'request': Icons.inventory_2};

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('Notifications')), actions: [
        IconButton(icon: const Icon(Icons.done_all), onPressed: () => run(context, () => Api.post('/notifications/read-all'), ok: '✓')),
      ]),
      body: Loader<List>(
        load: () async => await Api.get('/notifications') as List,
        builder: (context, rows, _) => rows.isEmpty
            ? ListView(children: const [EmptyNote('—')])
            : ListView(children: [
                for (final n in rows)
                  ListTile(
                    tileColor: n['read'] == true ? null : Colors.orange.shade50,
                    leading: Icon(icons[n['type']] ?? Icons.notifications, color: n['type'] == 'warning' ? Colors.red : brand),
                    title: Text(n['title'], style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text('${n['body'] ?? ''}\n${n['ago']}'),
                    isThreeLine: true,
                  ),
              ]),
      ),
    );
  }
}

class KpiScreen extends StatelessWidget {
  const KpiScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('My KPI'))),
      body: Loader<Map>(
        load: () async => await Api.get('/kpi') as Map,
        builder: (context, k, _) {
          final score = k['score'];
          final c = score == null ? Colors.grey : (score >= 90 ? Colors.green : (score >= 80 ? Colors.orange : Colors.red));
          Widget row(String l, dynamic v, String sub) => ListTile(title: Text(l), subtitle: Text(sub), trailing: Text(v == null ? '—' : '$v%', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)));
          return ListView(children: [
            const SizedBox(height: 24),
            Center(child: Text('${score ?? '—'}', style: TextStyle(fontSize: 72, fontWeight: FontWeight.w900, color: c))),
            Center(child: Text('${t('Score')} · ${k['month']}', style: const TextStyle(color: Colors.grey))),
            const SizedBox(height: 16),
            row('Tasks on time', k['on_time_rate'], '${k['tasks_on_time']}/${k['tasks_total']} tasks'),
            row('Tasks completed', k['completion_rate'], 'rework ${k['rework']}'),
            row(t('Attendance'), k['attendance_rate'], '${k['present_days']}/${k['rostered_days']} days'),
            row('Punctuality', k['punctuality_rate'], '${k['late_days']} late'),
          ]);
        },
      ),
    );
  }
}

class PayslipsScreen extends StatelessWidget {
  const PayslipsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('My payslips'))),
      body: Loader<List>(
        load: () async => await Api.get('/payslips') as List,
        builder: (context, rows, _) => rows.isEmpty
            ? ListView(children: const [EmptyNote('—')])
            : ListView(children: [
                for (final p in rows)
                  Card(
                    child: ExpansionTile(
                      title: Text('${p['month_label']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text('${t('Net pay')} ${tk(p['net'])} · ${p['payout_method']}'),
                      childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                      children: [
                        for (final e in {
                          'Basic': p['basic'], 'House rent': p['house_rent'], 'Medical': p['medical'], 'Conveyance': p['conveyance'],
                          'Overtime (${p['ot_hours']} h)': p['ot_amount'], 'KPI incentive': p['kpi_incentive'],
                        }.entries)
                          if ((num.tryParse('${e.value}') ?? 0) > 0) infoRow(e.key, tk(e.value)),
                        infoRow('Gross', tk(p['gross'])),
                        for (final e in {'Late deduction': p['late_deduction'], 'Absent deduction': p['absent_deduction'], 'Advance instalment': p['advance_deduction']}.entries)
                          if ((num.tryParse('${e.value}') ?? 0) > 0) infoRow(e.key, '− ${tk(e.value)}'),
                        const Divider(),
                        infoRow(t('Net pay'), tk(p['net'])),
                        infoRow('Days', '${p['present_days']}/${p['rostered_days']} present · ${p['late_count']} late'),
                      ],
                    ),
                  ),
              ]),
      ),
    );
  }
}
