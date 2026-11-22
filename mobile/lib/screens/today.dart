import 'dart:async';

import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/gps.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';
import 'me.dart';
import 'tasks.dart';

class TodayScreen extends StatefulWidget {
  const TodayScreen({super.key});
  @override
  State<TodayScreen> createState() => _TodayScreenState();
}

class _TodayScreenState extends State<TodayScreen> {
  final key = GlobalKey<LoaderState>();
  bool busy = false;
  Timer? timer;

  @override
  void initState() {
    super.initState();
    timer = Timer.periodic(const Duration(seconds: 60), (_) => key.currentState?.reload());
  }

  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  Future<Map<String, dynamic>> load() async {
    final home = Map<String, dynamic>.from(await Api.get('/home'));
    if (Session.I.on('attendance.proxy_checkin')) {
      home['proxy'] = await Api.get('/attendance/proxy-list');
    }
    return home;
  }

  Future<void> punch(String path, {Map<String, dynamic> extra = const {}}) async {
    setState(() => busy = true);
    try {
      toast(context, t('Getting GPS location…'));
      final fix = await gpsFix();
      if (!mounted) return;
      final r = await Api.post(path, {...fix, ...extra});
      if (mounted) toast(context, r['message'] ?? 'OK');
      key.currentState?.reload();
    } on ApiException catch (e) {
      if (mounted) toast(context, e.message, error: true);
    } catch (e) {
      if (mounted) toast(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = Session.I;
    return Scaffold(
      appBar: AppBar(
        title: Text('${t('Hello')}, ${s.name.split(' ').first}', style: const TextStyle(fontWeight: FontWeight.w700)),
        actions: [
          TextButton(onPressed: () => s.setLang(s.lang == 'en' ? 'bn' : 'en'), child: Text(s.lang == 'en' ? 'বাং' : 'EN')),
          IconButton(icon: const Icon(Icons.notifications_outlined), onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen()))),
        ],
      ),
      body: Loader<Map<String, dynamic>>(
        key: key,
        load: load,
        builder: (context, d, reload) {
          final att = Map<String, dynamic>.from(d['attendance'] ?? {});
          final shift = d['shift'];
          final status = att['status']?.toString() ?? '';
          final checkedIn = att['check_in'] != null;
          final checkedOut = att['check_out'] != null;
          final tasks = List<Map<String, dynamic>>.from((d['tasks'] as List).map((e) => Map<String, dynamic>.from(e)));
          final counts = Map<String, dynamic>.from(d['counts'] ?? {});
          final proxy = List<Map<String, dynamic>>.from(((d['proxy'] ?? []) as List).map((e) => Map<String, dynamic>.from(e)));
          return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            if (s.can('attendance.self'))
              Card(
                margin: const EdgeInsets.all(12),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      const Icon(Icons.schedule, color: brand),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          shift != null ? '${t('Your shift')}: ${shift['start']} – ${shift['end']}${shift['start2'] != null ? ' + ${shift['start2']}–${shift['end2']}' : ''}' : (d['is_off'] == true ? t('Weekly off') : (status == 'LEAVE' ? t('On leave today') : t('No shift today'))),
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                        ),
                      ),
                      StatusChip(status),
                    ]),
                    if (d['location'] != null) Padding(padding: const EdgeInsets.only(top: 4, left: 32), child: Text('📍 ${d['location']['name']}', style: const TextStyle(color: Colors.grey))),
                    if (checkedIn)
                      Padding(
                        padding: const EdgeInsets.only(top: 8, left: 32),
                        child: Text('In ${att['check_in']}${att['method'] != null && att['method'] != 'SELF' ? ' (${att['method']})' : ''}${(att['late_minutes'] ?? 0) > 0 ? ' · late ${att['late_minutes']} min' : ''}${checkedOut ? '   ·   Out ${att['check_out']}' : ''}'),
                      ),
                    const SizedBox(height: 14),
                    if (!checkedOut && status != 'LEAVE')
                      SizedBox(
                        width: double.infinity,
                        height: 64,
                        child: FilledButton.icon(
                          style: FilledButton.styleFrom(backgroundColor: checkedIn ? Colors.blueGrey.shade700 : Colors.green.shade700, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
                          onPressed: busy ? null : () => punch(checkedIn ? '/attendance/check-out' : '/attendance/check-in'),
                          icon: busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2)) : Icon(checkedIn ? Icons.logout : Icons.login, size: 28),
                          label: Text(checkedIn ? t('CHECK OUT') : t('CHECK IN'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                        ),
                      ),
                    if (checkedOut) Text('✓ ${t('Checked out')}', style: TextStyle(color: Colors.green.shade700, fontWeight: FontWeight.w700)),
                  ]),
                ),
              ),
            if (proxy.isNotEmpty) ...[
              SectionTitle(t('Check in for my team')),
              for (final p in proxy)
                Card(
                  child: ListTile(
                    leading: const CircleAvatar(child: Icon(Icons.person)),
                    title: Text(p['name'] ?? ''),
                    subtitle: Text('${p['shift'] ?? ''}\n${p['check_in'] != null ? 'In ${p['check_in']}' : ''}'),
                    isThreeLine: true,
                    trailing: p['check_out'] != null
                        ? const StatusChip('OUT')
                        : FilledButton.tonal(
                            onPressed: busy ? null : () => punch(p['check_in'] == null ? '/attendance/proxy-check-in' : '/attendance/proxy-check-out', extra: {'user_id': p['id']}),
                            child: Text(p['check_in'] == null ? t('CHECK IN') : t('CHECK OUT')),
                          ),
                  ),
                ),
            ],
            if ((counts['approvals'] ?? 0) > 0)
              Card(color: Colors.orange.shade50, child: ListTile(leading: const Icon(Icons.pending_actions, color: brand), title: Text('${counts['approvals']} ${t('pending approvals')}'), subtitle: Text(t('Requests')))),
            if (s.can('tasks.view_own')) ...[
              SectionTitle(t('My tasks today'), trailing: Text('${counts['tasks_open'] ?? 0} open', style: const TextStyle(color: Colors.grey))),
              if (tasks.isEmpty) EmptyNote('${t('No tasks')} ✓'),
              for (final task in tasks) TaskTile(task, onChanged: reload),
            ],
          ]);
        },
      ),
    );
  }
}
