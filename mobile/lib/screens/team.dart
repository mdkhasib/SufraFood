import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';
import 'org.dart';
import 'production.dart';

class TeamScreen extends StatelessWidget {
  const TeamScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = Session.I;
    final tabs = <(String, Widget)>[
      if (s.can('attendance.team_view')) (t('Who is in'), const _LiveBoard()),
      if (s.can('teams.view')) (t('Org chart'), const OrgChartView()),
      if (s.can('teams.view')) (t('Teams'), const TeamsView()),
      if (s.can('kpi.view_team')) ('KPI', const _TeamKpi()),
      if (s.can('attendance.proxy_grant')) (t('Proxy permissions'), const _ProxyGrants()),
      if (s.canAny(['production.work', 'production.manage'])) (t('Production'), const ProductionList()),
    ];
    if (tabs.isEmpty) return Scaffold(appBar: AppBar(title: Text(t('Team'))), body: EmptyNote(t('Team')));
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(title: Text(t('Team')), bottom: TabBar(isScrollable: tabs.length > 2, tabs: [for (final x in tabs) Tab(text: x.$1)])),
        body: TabBarView(children: [for (final x in tabs) x.$2]),
      ),
    );
  }
}

class _LiveBoard extends StatelessWidget {
  const _LiveBoard();

  @override
  Widget build(BuildContext context) {
    return Loader<Map>(
      load: () async => await Api.get('/attendance/team') as Map,
      builder: (context, d, reload) {
        final counts = Map<String, dynamic>.from(d['counts'] ?? {});
        final rows = List.from(d['rows']);
        const order = ['NOT_ARRIVED', 'LATE', 'IN', 'OUT', 'NOT_DUE', 'LEAVE', 'OFF', 'NO_SHIFT'];
        rows.sort((a, b) => order.indexOf(a['status']).compareTo(order.indexOf(b['status'])));
        return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: Wrap(spacing: 8, runSpacing: 8, children: [for (final e in counts.entries) Chip(label: Text('${e.key.replaceAll('_', ' ')}  ${e.value}'), backgroundColor: statusColor(e.key).withValues(alpha: .12))]),
          ),
          for (final r in rows)
            Card(
              child: ListTile(
                title: Text(r['user']['name'], style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text([
                  r['user']['position'] ?? '',
                  if (r['shift'] != null) r['shift'],
                  if (r['check_in'] != null) 'In ${r['check_in']}',
                  if (r['check_out'] != null) 'Out ${r['check_out']}',
                  if (r['method'] == 'PROXY') 'PROXY by ${r['by']}',
                  if ((r['late_minutes'] ?? 0) > 0) 'late ${r['late_minutes']}m',
                ].join(' · ')),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  StatusChip(r['status']),
                  if (r['ot_status'] == 'PENDING' || r['remote_status'] == 'PENDING')
                    InkWell(
                      onTap: () => _decide(context, r, reload),
                      child: Padding(padding: const EdgeInsets.only(top: 4), child: Text(r['remote_status'] == 'PENDING' ? 'Remote ✓?' : 'OT ✓?', style: const TextStyle(color: brand, fontWeight: FontWeight.w700))),
                    ),
                ]),
                onTap: r['user']['phone'] != null ? () => toast(context, '📞 ${r['user']['phone']}') : null,
              ),
            ),
        ]);
      },
    );
  }

  Future<void> _decide(BuildContext context, Map r, Future<void> Function() reload) async {
    final what = r['remote_status'] == 'PENDING' ? 'remote' : 'ot';
    final approve = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(what == 'remote' ? 'Remote check-in: ${r['user']['name']}' : 'Overtime ${((r['ot_minutes'] ?? 0) / 60).toStringAsFixed(1)} h: ${r['user']['name']}'),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(t('Reject'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t('Approve')))],
      ),
    );
    if (approve == null || !context.mounted) return;
    await run(context, () => Api.post('/attendance/${r['attendance_id']}/${what == 'remote' ? 'remote' : 'ot'}-decision', {'approve': approve}));
    await reload();
  }
}

class _TeamKpi extends StatelessWidget {
  const _TeamKpi();
  @override
  Widget build(BuildContext context) => Loader<List>(
        load: () async => await Api.get('/kpi/team') as List,
        builder: (context, rows, _) => ListView(children: [
          for (final (i, r) in rows.indexed)
            ListTile(
              leading: CircleAvatar(child: Text('${i + 1}')),
              title: Text(r['user']['name']),
              subtitle: Text('tasks on time ${r['on_time_rate'] ?? '—'}% · attendance ${r['attendance_rate'] ?? '—'}% · late ${r['late_days']}'),
              trailing: Text('${r['score'] ?? '—'}', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: (r['score'] ?? 0) >= 90 ? Colors.green : ((r['score'] ?? 0) >= 80 ? Colors.orange : Colors.red))),
            ),
        ]),
      );
}

class _ProxyGrants extends StatelessWidget {
  const _ProxyGrants();

  @override
  Widget build(BuildContext context) {
    return Loader<Map>(
      load: () async => {'grants': await Api.get('/attendance/proxy-grants'), 'team': await Api.get('/team')},
      builder: (context, d, reload) => ListView(padding: const EdgeInsets.only(bottom: 90), children: [
        const Padding(padding: EdgeInsets.all(12), child: Text('Exception only: a staff member can check in a fixed list (max 2) of your team when their phone is not working.', style: TextStyle(color: Colors.grey))),
        for (final g in d['grants'])
          Card(
            child: ListTile(
              title: Text('${g['proxy']['name']} →'),
              subtitle: Text('${(g['staff'] as List).map((x) => x['name']).join(', ')}\n${g['valid_to'] != null ? 'until ${g['valid_to']}' : 'permanent'} ${g['reason'] ?? ''}'),
              isThreeLine: true,
              trailing: IconButton(icon: const Icon(Icons.delete_outline, color: Colors.red), onPressed: () async {
                await run(context, () => Api.delete('/attendance/proxy-grants/${g['id']}'));
                await reload();
              }),
            ),
          ),
        Padding(
          padding: const EdgeInsets.all(12),
          child: FilledButton.icon(onPressed: () => _add(context, List.from(d['team']), reload), icon: const Icon(Icons.add), label: const Text('Give proxy permission')),
        ),
      ]),
    );
  }

  Future<void> _add(BuildContext context, List team, Future<void> Function() reload) async {
    int? proxy;
    final staff = <int>{};
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AlertDialog(
          title: const Text('Proxy permission'),
          content: SizedBox(
            width: 360,
            child: ListView(shrinkWrap: true, children: [
              DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Proxy person'), items: [for (final p in team) DropdownMenuItem(value: p['id'] as int, child: Text(p['name']))], onChanged: (v) => proxy = v),
              const SizedBox(height: 8),
              const Text('May check in (max 2):'),
              for (final p in team)
                CheckboxListTile(dense: true, value: staff.contains(p['id']), onChanged: (v) => set(() => v == true ? staff.add(p['id']) : staff.remove(p['id'])), title: Text(p['name'])),
              TextField(controller: reason, decoration: InputDecoration(labelText: t('Reason'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(t('Cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t('Save')))],
        ),
      ),
    );
    if (ok == true && proxy != null && context.mounted) {
      await run(context, () => Api.post('/attendance/proxy-grants', {'proxy_user_id': proxy, 'staff_ids': staff.toList(), 'reason': reason.text}));
      await reload();
    }
  }
}
