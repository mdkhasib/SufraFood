import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';

class AttendanceScreen extends StatelessWidget {
  const AttendanceScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = Session.I;
    return Scaffold(
      appBar: AppBar(title: Text(t('Attendance')), actions: [
        if (s.can('leave.request')) TextButton.icon(onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const LeaveScreen())), icon: const Icon(Icons.beach_access), label: Text(t('Leave'))),
      ]),
      body: Loader<Map<String, dynamic>>(
        load: () async => {
          'month': await Api.get('/attendance/month'),
          'roster': s.can('shifts.view_own') ? await Api.get('/roster') : [],
        },
        builder: (context, d, reload) {
          final days = List.from(d['month']['days']).reversed.where((x) => !['SCHEDULED', 'NO_SHIFT'].contains(x['status']) && DateTime.parse(x['date']).isBefore(DateTime.now().add(const Duration(days: 1)).copyWith(hour: 0, minute: 0))).toList();
          final sum = Map<String, dynamic>.from(d['month']['summary'] ?? {});
          int c(List<String> k) => k.fold(0, (a, b) => a + ((sum[b] ?? 0) as int));
          return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            Card(
              margin: const EdgeInsets.all(12),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
                  _stat(t('Present'), c(['PRESENT', 'IN', 'OUT', 'LATE']), Colors.green),
                  _stat(t('Late'), c(['LATE']), Colors.orange),
                  _stat(t('Absent'), c(['ABSENT']), Colors.red),
                  _stat(t('Leave'), c(['LEAVE']), Colors.purple),
                ]),
              ),
            ),
            if ((d['roster'] as List).isNotEmpty) ...[
              SectionTitle(t('Upcoming shifts')),
              SizedBox(
                height: 74,
                child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 8), children: [
                  for (final r in d['roster'])
                    Container(
                      width: 120,
                      margin: const EdgeInsets.symmetric(horizontal: 4),
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(color: r['off'] == true ? Colors.grey.shade200 : Colors.white, borderRadius: BorderRadius.circular(10), border: Border.all(color: Colors.grey.shade300)),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(r['day'], style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                        Text(r['off'] == true ? t('Off') : (r['shift'] ?? '—'), maxLines: 2, style: const TextStyle(fontSize: 11)),
                      ]),
                    ),
                ]),
              ),
            ],
            SectionTitle(t('This month'), trailing: s.can('attendance.correction_request') ? TextButton(onPressed: () => _correction(context, reload), child: Text(t('Fix missed check-in'))) : null),
            for (final x in days)
              ListTile(
                dense: true,
                title: Text(x['date'], style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text([x['shift'] ?? '', if (x['in'] != null) 'In ${x['in']}', if (x['out'] != null) 'Out ${x['out']}', if ((x['late'] ?? 0) > 0) 'late ${x['late']}m', if (x['method'] != null && x['method'] != 'SELF') x['method']].where((e) => '$e'.isNotEmpty).join(' · ')),
                trailing: StatusChip(x['status']),
              ),
          ]);
        },
      ),
    );
  }

  Widget _stat(String l, int v, Color c) => Column(children: [Text('$v', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: c)), Text(l, style: const TextStyle(fontSize: 12))]);

  Future<void> _correction(BuildContext context, Future<void> Function() reload) async {
    DateTime date = DateTime.now().subtract(const Duration(days: 1));
    final inC = TextEditingController(text: '08:00');
    final outC = TextEditingController(text: '16:00');
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AlertDialog(
          title: Text(t('Fix missed check-in')),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            ListTile(contentPadding: EdgeInsets.zero, title: Text('${date.day}/${date.month}/${date.year}'), trailing: const Icon(Icons.event), onTap: () async {
              final d = await showDatePicker(context: ctx, initialDate: date, firstDate: DateTime.now().subtract(const Duration(days: 40)), lastDate: DateTime.now());
              if (d != null) set(() => date = d);
            }),
            TextField(controller: inC, decoration: const InputDecoration(labelText: 'In (HH:MM)')),
            TextField(controller: outC, decoration: const InputDecoration(labelText: 'Out (HH:MM)')),
            TextField(controller: reason, decoration: InputDecoration(labelText: t('Reason'))),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(t('Cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t('Send')))],
        ),
      ),
    );
    if (ok == true && context.mounted) {
      await run(context, () => Api.post('/attendance/corrections', {'date': '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}', 'check_in': inC.text, 'check_out': outC.text, 'reason': reason.text}));
      await reload();
    }
  }
}

class LeaveScreen extends StatelessWidget {
  const LeaveScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('Leave'))),
      body: Loader<Map>(
        load: () async => await Api.get('/leave') as Map,
        builder: (context, d, reload) => ListView(padding: const EdgeInsets.all(12), children: [
          for (final ty in d['types'])
            Card(child: ListTile(title: Text(Session.I.lang == 'bn' && ty['name_bn'] != null ? ty['name_bn'] : ty['name']), trailing: Text(ty['days_per_year'] > 0 ? '${ty['left']} / ${ty['days_per_year']}' : '—', style: const TextStyle(fontWeight: FontWeight.w700)))),
          const SizedBox(height: 8),
          FilledButton.icon(onPressed: () => _request(context, List.from(d['types']), reload), icon: const Icon(Icons.add), label: Text(t('Request leave'))),
          SectionTitle(t('Mine')),
          for (final r in d['requests']) ListTile(title: Text('${r['type']} · ${r['from']} – ${r['to']}'), subtitle: Text('${r['days']} day(s) ${r['reason'] ?? ''}'), trailing: StatusChip(r['status'])),
        ]),
      ),
    );
  }

  Future<void> _request(BuildContext context, List types, Future<void> Function() reload) async {
    int type = types.first['id'];
    DateTimeRange? range;
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AlertDialog(
          title: Text(t('Request leave')),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<int>(initialValue: type, items: [for (final x in types) DropdownMenuItem(value: x['id'] as int, child: Text(x['name']))], onChanged: (v) => type = v!),
            ListTile(contentPadding: EdgeInsets.zero, title: Text(range == null ? 'Pick dates' : '${range!.start.day}/${range!.start.month} – ${range!.end.day}/${range!.end.month}'), trailing: const Icon(Icons.date_range), onTap: () async {
              final r = await showDateRangePicker(context: ctx, firstDate: DateTime.now().subtract(const Duration(days: 7)), lastDate: DateTime.now().add(const Duration(days: 120)));
              if (r != null) set(() => range = r);
            }),
            TextField(controller: reason, decoration: InputDecoration(labelText: t('Reason'))),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(t('Cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, range != null), child: Text(t('Send')))],
        ),
      ),
    );
    if (ok == true && range != null && context.mounted) {
      String f(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
      await run(context, () => Api.post('/leave', {'leave_type_id': type, 'from_date': f(range!.start), 'to_date': f(range!.end), 'reason': reason.text}));
      await reload();
    }
  }
}
