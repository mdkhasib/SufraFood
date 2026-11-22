import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';

String taskTitle(Map t) => (Session.I.lang == 'bn' && (t['title_bn'] ?? '').toString().isNotEmpty) ? t['title_bn'] : (t['title'] ?? '');

class TaskTile extends StatelessWidget {
  final Map<String, dynamic> task;
  final Future<void> Function() onChanged;
  final bool team;
  const TaskTile(this.task, {super.key, required this.onChanged, this.team = false});

  @override
  Widget build(BuildContext context) {
    final overdue = task['overdue'] == true;
    final status = overdue ? 'OVERDUE' : task['status'];
    return Card(
      child: ListTile(
        onTap: () async {
          await showModalBottomSheet(context: context, isScrollControlled: true, showDragHandle: true, builder: (_) => TaskSheet(task: task, team: team));
          await onChanged();
        },
        leading: Icon(
          task['status'] == 'DONE' ? Icons.check_circle : (overdue ? Icons.warning_amber : (task['priority'] == 'URGENT' ? Icons.priority_high : Icons.radio_button_unchecked)),
          color: task['status'] == 'DONE' ? Colors.green : (overdue || task['priority'] == 'URGENT' ? Colors.red : (task['priority'] == 'HIGH' ? Colors.orange : Colors.grey)),
        ),
        title: Text(taskTitle(task), style: TextStyle(fontWeight: FontWeight.w600, decoration: task['status'] == 'DONE' ? TextDecoration.lineThrough : null)),
        subtitle: Text([
          '${t('Due')} ${task['due_label']}',
          if (team && task['assignee'] != null) task['assignee']['name'],
          if (task['priority'] == 'URGENT') t('Top priority') else if (task['priority'] == 'HIGH') t('High'),
          if (task['team'] != null) task['team'],
          if (task['proof_required'] == true) 'photo',
          if (task['recurring'] == true) '↻',
          if ((task['reminders_sent'] ?? 0) > 0) '${t('reminded')} ×${task['reminders_sent']}',
        ].join(' · ')),
        trailing: StatusChip(status),
      ),
    );
  }
}

class TaskSheet extends StatefulWidget {
  final Map<String, dynamic> task;
  final bool team;
  const TaskSheet({super.key, required this.task, this.team = false});
  @override
  State<TaskSheet> createState() => _TaskSheetState();
}

class _TaskSheetState extends State<TaskSheet> {
  final note = TextEditingController();
  String? photo;
  bool busy = false;

  Future<void> act(Future<dynamic> Function() f) async {
    setState(() => busy = true);
    final r = await run(context, f);
    if (mounted) setState(() => busy = false);
    if (r != null && mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final tk = widget.task;
    final id = tk['id'];
    final mine = !widget.team;
    final st = tk['status'];
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.of(context).viewInsets.bottom + 24),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(taskTitle(tk), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Row(children: [StatusChip(tk['overdue'] == true ? 'OVERDUE' : st), const SizedBox(width: 8), Text('${t('Due')} ${tk['due_label']}')]),
          if ((tk['description'] ?? '').toString().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tk['description'])),
          if (tk['assigned_by'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('From: ${tk['assigned_by']}', style: const TextStyle(color: Colors.grey))),
          if (widget.team && tk['assignee'] != null) Text('To: ${tk['assignee']['name']}', style: const TextStyle(color: Colors.grey)),
          if (tk['proof_photo'] != null) Padding(padding: const EdgeInsets.only(top: 12), child: ClipRRect(borderRadius: BorderRadius.circular(10), child: Image.network(tk['proof_photo'], height: 180, fit: BoxFit.cover, errorBuilder: (_, _, _) => const Text('photo')))),
          if ((tk['proof_note'] ?? '').toString().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text('“${tk['proof_note']}”')),
          if ((tk['rework_count'] ?? 0) > 0) Text('Rework ×${tk['rework_count']}', style: const TextStyle(color: Colors.orange)),
          if (tk['priority'] == 'URGENT' || tk['priority'] == 'HIGH' || tk['team'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Wrap(spacing: 6, children: [
                if (tk['priority'] == 'URGENT') StatusChip('URGENT'),
                if (tk['priority'] == 'HIGH') const StatusChip('HIGH'),
                if (tk['team'] != null) Chip(label: Text(tk['team']), visualDensity: VisualDensity.compact),
              ]),
            ),
          if (tk['remind_before'] != null || tk['remind_every'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text([
                if (tk['remind_before'] != null) '${t('Reminder')} ${tk['remind_before']} min ${t('before')}',
                if (tk['remind_every'] != null) '${t('every')} ${tk['remind_every']} min ${t('if late')}',
                if ((tk['reminders_sent'] ?? 0) > 0) '${tk['reminders_sent']} ${t('sent')}',
              ].join(' · '), style: const TextStyle(color: Colors.orange)),
            ),
          const SizedBox(height: 16),
          if (widget.team && tk['can_remind'] == true)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: busy ? null : () => act(() => Api.post('/tasks/$id/remind')),
                  icon: const Icon(Icons.notifications_active_outlined),
                  label: Text('${t('Remind now')}${tk['last_reminded'] != null ? ' (${t('last')} ${tk['last_reminded']})' : ''}'),
                ),
              ),
            ),
          if (mine && st == 'ASSIGNED')
            SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: busy ? null : () => act(() => Api.post('/tasks/$id/start')), icon: const Icon(Icons.play_arrow), label: Text(t('Start')))),
          if (mine && (st == 'ASSIGNED' || st == 'STARTED')) ...[
            const SizedBox(height: 8),
            if (tk['proof_required'] == true)
              OutlinedButton.icon(
                onPressed: () async {
                  final x = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 60, maxWidth: 1280);
                  if (x != null) setState(() => photo = x.path);
                },
                icon: Icon(photo == null ? Icons.photo_camera : Icons.check, color: photo == null ? null : Colors.green),
                label: Text(photo == null ? '${t('Take photo')} (${t('Photo proof needed')})' : '✓ photo ready'),
              ),
            TextField(controller: note, decoration: InputDecoration(labelText: t('Note'))),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
                onPressed: busy ? null : () => act(() => Api.multipart('/tasks/$id/complete', {'note': note.text}, filePath: photo)),
                icon: const Icon(Icons.check),
                label: Text(t('Done'), style: const TextStyle(fontSize: 18)),
              ),
            ),
          ],
          if (widget.team && st == 'SUBMITTED' && Session.I.can('tasks.assign')) ...[
            TextField(controller: note, decoration: InputDecoration(labelText: '${t('Note')} (rework)')),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: OutlinedButton(onPressed: busy ? null : () => act(() => Api.post('/tasks/$id/review', {'approve': false, 'note': note.text.isEmpty ? 'Please redo' : note.text})), child: Text(t('Rework')))),
              const SizedBox(width: 12),
              Expanded(child: FilledButton(onPressed: busy ? null : () => act(() => Api.post('/tasks/$id/review', {'approve': true})), child: Text(t('Approve')))),
            ]),
          ],
        ]),
      ),
    );
  }
}

class TasksScreen extends StatelessWidget {
  const TasksScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final team = Session.I.can('tasks.view_team');
    final tabs = [
      _TaskList(scope: 'mine', filter: 'open', label: t('Open')),
      _TaskList(scope: 'mine', filter: 'done', label: t('Done')),
      if (team) _TaskList(scope: 'team', filter: 'open', label: t('Team tasks'), team: true),
      if (team) _TaskList(scope: 'team', filter: 'review', label: t('Review'), team: true),
      if (Session.I.can('tasks.assign')) _TaskList(scope: 'team', filter: 'assigned_by_me', label: t('Assigned by me'), team: true),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Text(t('Tasks')),
          bottom: TabBar(isScrollable: tabs.length > 3, tabs: [for (final x in tabs) Tab(text: x.label)]),
        ),
        floatingActionButton: Session.I.can('tasks.assign')
            ? FloatingActionButton.extended(onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AssignTaskScreen())), icon: const Icon(Icons.add_task), label: Text(t('Assign task')))
            : null,
        body: TabBarView(children: tabs),
      ),
    );
  }
}

class _TaskList extends StatelessWidget {
  final String scope, filter, label;
  final bool team;
  const _TaskList({required this.scope, required this.filter, required this.label, this.team = false});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/tasks', {'scope': scope, 'filter': filter}) as List,
      builder: (context, items, reload) => items.isEmpty
          ? ListView(children: [EmptyNote(t('No tasks'))])
          : ListView(padding: const EdgeInsets.only(bottom: 90), children: [for (final x in items) TaskTile(Map<String, dynamic>.from(x), onChanged: reload, team: team)]),
    );
  }
}

class AssignTaskScreen extends StatefulWidget {
  final int? presetId;
  final String? presetName;
  const AssignTaskScreen({super.key, this.presetId, this.presetName});
  @override
  State<AssignTaskScreen> createState() => _AssignTaskScreenState();
}

class _AssignTaskScreenState extends State<AssignTaskScreen> {
  final title = TextEditingController();
  final titleBn = TextEditingController();
  final desc = TextEditingController();
  DateTime due = DateTime.now().add(const Duration(hours: 2));
  bool proof = false;
  String priority = 'NORMAL';
  final picked = <int>{};
  final pickedTeams = <int>{};
  String teamMode = 'LEAD';
  bool teamsTab = false;
  int? remindBefore = 30;
  int? remindEvery; // null = auto by priority
  bool notifyDone = true, notifyStart = false;
  bool preset = false;
  bool defaultsSet = false;
  String q = '';

  @override
  void initState() {
    super.initState();
    if (widget.presetId != null) {
      preset = true;
      picked.add(widget.presetId!);
    }
  }

  Widget _section(String s) => Padding(padding: const EdgeInsets.only(top: 18, bottom: 6), child: Text(s, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)));

  bool _hit(Map p) => q.isEmpty || '${p['name']} ${p['code']} ${p['position']}'.toLowerCase().contains(q);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('Assign task'))),
      body: Loader<Map>(
        load: () async => await Api.get('/assign-options') as Map,
        builder: (context, opt, _) {
          final people = List.from(opt['people'] ?? []);
          final teams = List.from(opt['teams'] ?? []);
          final rem = opt['reminders'] == true;
          final defs = Map<String, dynamic>.from(opt['defaults'] ?? {});
          if (!defaultsSet) {
            remindBefore = defs['remind_before'] ?? 30;
            defaultsSet = true;
          }
          final direct = people.where((p) => p['direct'] == true && _hit(p)).toList();
          final leads = people.where((p) => p['direct'] != true && _hit(p)).toList();
          Widget personTile(Map p) => CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                value: picked.contains(p['id']),
                onChanged: (v) => setState(() => v == true ? picked.add(p['id']) : picked.remove(p['id'])),
                title: Text(p['name'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text([p['position'] ?? '', p['location'] ?? '', if ((p['leads'] ?? 0) > 0) '${p['leads']} ${t('under')}'].where((x) => '$x'.isNotEmpty).join(' · ')),
              );
          final autoEvery = priority == 'URGENT' ? defs['urgent_every'] : (priority == 'HIGH' ? defs['high_every'] : null);
          return ListView(padding: const EdgeInsets.all(16), children: [
            TextField(controller: title, decoration: InputDecoration(labelText: t('Title'), border: const OutlineInputBorder())),
            const SizedBox(height: 10),
            TextField(controller: titleBn, decoration: const InputDecoration(labelText: 'শিরোনাম (বাংলা)', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            TextField(controller: desc, maxLines: 2, decoration: InputDecoration(labelText: t('Details'), border: const OutlineInputBorder())),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event),
              title: Text('${t('Due')}: ${due.day}/${due.month} ${TimeOfDay.fromDateTime(due).format(context)}'),
              trailing: const Icon(Icons.edit_calendar_outlined),
              onTap: () async {
                final d = await showDatePicker(context: context, initialDate: due, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime.now().add(const Duration(days: 60)));
                if (d == null || !context.mounted) return;
                final tm = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(due));
                if (tm == null) return;
                setState(() => due = DateTime(d.year, d.month, d.day, tm.hour, tm.minute));
              },
            ),
            Text(t('Priority'), style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, children: [
              for (final p in const [('LOW', 'Low'), ('NORMAL', 'Normal'), ('HIGH', 'High'), ('URGENT', 'Top priority')])
                ChoiceChip(
                  label: Text(t(p.$2)),
                  selected: priority == p.$1,
                  selectedColor: (p.$1 == 'URGENT' ? Colors.red : (p.$1 == 'HIGH' ? Colors.orange : Colors.blueGrey)).withValues(alpha: .25),
                  onSelected: (_) => setState(() => priority = p.$1),
                ),
            ]),
            if (opt['proof'] == true) SwitchListTile(contentPadding: EdgeInsets.zero, value: proof, onChanged: (v) => setState(() => proof = v), title: Text(t('Photo proof needed'))),
            _section(t('Give it to')),
            if (preset)
              Card(
                color: brand.withValues(alpha: .08),
                child: ListTile(
                  leading: const Icon(Icons.person, color: brand),
                  title: Text(widget.presetName ?? ''),
                  trailing: TextButton(
                    onPressed: () => setState(() {
                      preset = false;
                      picked.clear();
                    }),
                    child: Text(t('Change')),
                  ),
                ),
              )
            else ...[
              if (teams.isNotEmpty)
                SegmentedButton<bool>(
                  segments: [
                    ButtonSegment(value: false, label: Text('${t('People')} (${people.length})'), icon: const Icon(Icons.person_outline)),
                    ButtonSegment(value: true, label: Text('${t('Teams')} (${teams.length})'), icon: const Icon(Icons.groups_outlined)),
                  ],
                  selected: {teamsTab},
                  onSelectionChanged: (s) => setState(() => teamsTab = s.first),
                ),
              const SizedBox(height: 8),
              if (!teamsTab) ...[
                TextField(decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: t('search name'), isDense: true), onChanged: (v) => setState(() => q = v.toLowerCase())),
                if (direct.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(t('Reports to you directly'), style: const TextStyle(color: Colors.grey, fontSize: 12, fontWeight: FontWeight.w700))),
                for (final p in direct) personTile(p),
                if (leads.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(t('Team leads & managers below you'), style: const TextStyle(color: Colors.grey, fontSize: 12, fontWeight: FontWeight.w700))),
                for (final p in leads) personTile(p),
                if (people.isEmpty) EmptyNote(t('Nobody reports to you yet')),
                Padding(padding: const EdgeInsets.only(top: 6), child: Text(t('Front-line staff are reached through their team lead, a team, or the org chart.'), style: const TextStyle(color: Colors.grey, fontSize: 11))),
              ] else ...[
                for (final tm in teams)
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    value: pickedTeams.contains(tm['id']),
                    onChanged: (v) => setState(() => v == true ? pickedTeams.add(tm['id']) : pickedTeams.remove(tm['id'])),
                    title: Text(tm['name'], style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text('${t('Lead')}: ${tm['lead'] ?? '-'} · ${tm['members']} ${t('members')} · ${tm['location'] ?? ''}'),
                  ),
                const SizedBox(height: 6),
                Wrap(spacing: 6, runSpacing: 6, children: [
                  ChoiceChip(label: Text(t('Team lead shares it out')), selected: teamMode == 'LEAD', onSelected: (_) => setState(() => teamMode = 'LEAD')),
                  ChoiceChip(label: Text(t('Every member gets one')), selected: teamMode == 'EACH', onSelected: (_) => setState(() => teamMode = 'EACH')),
                ]),
              ],
            ],
            if (rem) ...[
              _section(t('Reminders & alerts')),
              DropdownButtonFormField<int?>(
                initialValue: remindBefore,
                decoration: InputDecoration(labelText: t('Remind before due time'), border: const OutlineInputBorder(), isDense: true),
                items: [DropdownMenuItem<int?>(value: null, child: Text(t('No'))), for (final m in const [10, 15, 30, 60, 120]) DropdownMenuItem<int?>(value: m, child: Text(m < 60 ? '$m min' : '${m ~/ 60} h'))],
                onChanged: (v) => setState(() => remindBefore = v),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<int?>(
                initialValue: remindEvery,
                decoration: InputDecoration(
                  labelText: t('If late, remind again every'),
                  border: const OutlineInputBorder(),
                  isDense: true,
                  helperText: remindEvery == null ? (autoEvery != null ? '${t('Auto')}: $autoEvery min' : t('Normal/low: no repeat unless you choose one')) : null,
                ),
                items: [DropdownMenuItem<int?>(value: null, child: Text(t('Auto: by priority'))), for (final m in const [10, 15, 30, 60]) DropdownMenuItem<int?>(value: m, child: Text('$m min'))],
                onChanged: (v) => setState(() => remindEvery = v),
              ),
              SwitchListTile(contentPadding: EdgeInsets.zero, value: notifyDone, onChanged: (v) => setState(() => notifyDone = v), title: Text(t('Notify me when it is finished'))),
              SwitchListTile(contentPadding: EdgeInsets.zero, value: notifyStart, onChanged: (v) => setState(() => notifyStart = v), title: Text(t('Notify me when it is started'))),
            ],
            const SizedBox(height: 16),
            SizedBox(
              height: 50,
              child: FilledButton(
                onPressed: () async {
                  if (title.text.trim().isEmpty) return toast(context, t('Write a title'), error: true);
                  if (picked.isEmpty && pickedTeams.isEmpty) return toast(context, t('Choose a person or a team'), error: true);
                  final r = await run(context, () => Api.post('/tasks', {
                        'title': title.text.trim(), 'title_bn': titleBn.text, 'description': desc.text, 'due_at': due.toIso8601String(),
                        'priority': priority, 'proof_required': proof,
                        'assignee_ids': picked.toList(), 'team_ids': preset ? <int>[] : pickedTeams.toList(), 'team_mode': teamMode,
                        if (rem) ...{'remind_before_minutes': remindBefore, 'remind_every_minutes': remindEvery, 'notify_on_done': notifyDone, 'notify_on_start': notifyStart},
                      }));
                  if (r != null && context.mounted) Navigator.pop(context);
                },
                child: Text('${t('Assign')} (${picked.length + (preset ? 0 : pickedTeams.length)})', style: const TextStyle(fontSize: 16)),
              ),
            ),
          ]);
        },
      ),
    );
  }
}
