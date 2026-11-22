import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';
import 'tasks.dart';

const _teamColors = {
  'slate': Color(0xFF64748B), 'red': Color(0xFFEF4444), 'orange': Color(0xFFF97316), 'amber': Color(0xFFF59E0B), 'emerald': Color(0xFF10B981),
  'teal': Color(0xFF14B8A6), 'sky': Color(0xFF0EA5E9), 'blue': Color(0xFF3B82F6), 'indigo': Color(0xFF6366F1), 'violet': Color(0xFF8B5CF6), 'pink': Color(0xFFEC4899),
};
Color teamColor(String? c) => _teamColors[c] ?? Colors.blueGrey;

String _initials(String name) => name.split(' ').where((w) => w.isNotEmpty).take(2).map((w) => w[0]).join();
String _name(Map n) => Session.I.lang == 'bn' && (n['name_bn'] ?? '').toString().isNotEmpty ? n['name_bn'] : (n['name'] ?? '');

/// Who reports to whom, as an expandable tree. Tap a person to give them a task.
class OrgChartView extends StatefulWidget {
  final int? root;
  const OrgChartView({super.key, this.root});
  @override
  State<OrgChartView> createState() => _OrgChartViewState();
}

class _OrgChartViewState extends State<OrgChartView> {
  String q = '';

  bool _matches(Map n) {
    if (q.isEmpty) return true;
    final s = '${n['name']} ${n['code']} ${n['position']} ${n['team']?['name'] ?? ''}'.toLowerCase();
    return s.contains(q) || (n['children'] as List).any((c) => _matches(c));
  }

  @override
  Widget build(BuildContext context) {
    return Loader<Map>(
      load: () async => await Api.get('/org', {if (widget.root != null) 'root': '${widget.root}'}) as Map,
      builder: (context, d, reload) {
        final tree = List.from(d['tree'] ?? []);
        return ListView(padding: const EdgeInsets.only(bottom: 90), children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: TextField(
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: t('Find a person or team'), isDense: true, border: const OutlineInputBorder()),
              onChanged: (v) => setState(() => q = v.trim().toLowerCase()),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
            child: Text(t('Lines show who reports to whom. Tap a person to give them a task.'), style: const TextStyle(color: Colors.grey, fontSize: 12)),
          ),
          if (tree.isEmpty) EmptyNote(t('Nobody to show')),
          for (final n in tree.where((n) => _matches(n))) _node(context, Map<String, dynamic>.from(n), 0),
        ]);
      },
    );
  }

  Widget _node(BuildContext context, Map<String, dynamic> n, int depth) {
    final kids = List.from(n['children'] ?? []).where((c) => _matches(c)).toList();
    final c = teamColor(n['team']?['color']);
    final hit = q.isNotEmpty && '${n['name']} ${n['code']} ${n['position']}'.toLowerCase().contains(q);
    final tile = ListTile(
      dense: true,
      contentPadding: EdgeInsets.only(left: 12.0 + depth * 18, right: 8),
      leading: CircleAvatar(radius: 18, backgroundColor: c.withValues(alpha: .15), child: Text(_initials(n['name'] ?? ''), style: TextStyle(color: c, fontWeight: FontWeight.w800, fontSize: 12))),
      title: Text(_name(n), style: TextStyle(fontWeight: FontWeight.w700, color: hit ? brand : null)),
      subtitle: Text([n['position'] ?? '', n['location'] ?? '', if (n['team'] != null) n['team']['name'], if ((n['below'] ?? 0) > 0) '${n['below']} ${t('below')}'].where((x) => '$x'.isNotEmpty).join(' · '), maxLines: 2),
      trailing: n['can_assign'] == true ? IconButton(tooltip: t('Assign task'), icon: const Icon(Icons.add_task, color: brand), onPressed: () => _assign(context, n)) : null,
      onTap: () => _actions(context, n),
    );
    if (kids.isEmpty) return Container(decoration: BoxDecoration(border: Border(left: BorderSide(color: c, width: 3))), child: tile);
    return Container(
      decoration: BoxDecoration(border: Border(left: BorderSide(color: c, width: 3))),
      child: ExpansionTile(
        key: PageStorageKey('org-${n['id']}-$q'),
        initiallyExpanded: depth < 1 || q.isNotEmpty,
        tilePadding: EdgeInsets.zero,
        title: tile,
        children: [for (final k in kids) _node(context, Map<String, dynamic>.from(k), depth + 1)],
      ),
    );
  }

  void _assign(BuildContext context, Map n) {
    Navigator.push(context, MaterialPageRoute(builder: (_) => AssignTaskScreen(presetId: n['id'], presetName: n['name'])));
  }

  Future<void> _actions(BuildContext context, Map n) async {
    await showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          ListTile(
            title: Text(_name(n), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
            subtitle: Text('${n['position'] ?? ''} · ${n['code']} · ${n['location_name'] ?? ''}${n['team'] != null ? '\n${t('Team')}: ${n['team']['name']}' : ''}'),
          ),
          if (n['can_assign'] == true)
            ListTile(leading: const Icon(Icons.add_task, color: brand), title: Text(t('Assign task')), onTap: () {
              Navigator.pop(ctx);
              _assign(context, n);
            }),
          if ((n['below'] ?? 0) > 0)
            ListTile(leading: const Icon(Icons.account_tree_outlined), title: Text('${t('Show only their team')} (${n['below']})'), onTap: () {
              Navigator.pop(ctx);
              Navigator.push(context, MaterialPageRoute(builder: (_) => Scaffold(appBar: AppBar(title: Text(n['name'])), body: OrgChartView(root: n['id']))));
            }),
          if (n['phone'] != null) ListTile(leading: const Icon(Icons.phone), title: Text('${n['phone']}')),
        ]),
      ),
    );
  }
}

/// Teams with their lead and members.
class TeamsView extends StatelessWidget {
  const TeamsView({super.key});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/teams') as List,
      builder: (context, teams, _) => teams.isEmpty
          ? ListView(children: [EmptyNote(t('No teams yet'))])
          : ListView(padding: const EdgeInsets.only(bottom: 90), children: [
              for (final tm in teams)
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: teamColor(tm['color']).withValues(alpha: .5))),
                  child: ExpansionTile(
                    leading: CircleAvatar(radius: 8, backgroundColor: teamColor(tm['color'])),
                    title: Text(Session.I.lang == 'bn' && (tm['name_bn'] ?? '').toString().isNotEmpty ? tm['name_bn'] : tm['name'], style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text([
                      if (tm['lead'] != null) '${t('Lead')}: ${tm['lead']['name']}',
                      '${(tm['members'] as List).length} ${t('members')}',
                      if (tm['parent'] != null) '${t('under')} ${tm['parent']}',
                    ].join(' · ')),
                    children: [
                      for (final m in tm['members'])
                        ListTile(dense: true, leading: const Icon(Icons.person_outline, size: 18), title: Text(m['name']), subtitle: Text('${m['position'] ?? ''} · ${m['code']}')),
                    ],
                  ),
                ),
            ]),
    );
  }
}
