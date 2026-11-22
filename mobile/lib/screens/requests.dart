import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';

class RequestsScreen extends StatelessWidget {
  const RequestsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = Session.I;
    final tabs = <(String, Widget)>[
      (t('Approvals'), const ApprovalsList()),
      (t('Mine'), const _ReqList(box: 'mine')),
      if (s.canAny(['req.fulfil', 'purchase.manage'])) (t('Incoming'), const _ReqList(box: 'incoming')),
      if (s.can('inventory.view')) (t('Stock'), const StockList()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(title: Text(t('Requests')), bottom: TabBar(tabs: [for (final x in tabs) Tab(text: x.$1)])),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const NewRequestScreen())),
          icon: const Icon(Icons.add),
          label: Text(t('New request')),
        ),
        body: TabBarView(children: [for (final x in tabs) x.$2]),
      ),
    );
  }
}

class ApprovalsList extends StatelessWidget {
  const ApprovalsList({super.key});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/approvals') as List,
      builder: (context, rows, reload) => rows.isEmpty
          ? ListView(children: const [EmptyNote('Nothing waiting for you 🎉')])
          : ListView(padding: const EdgeInsets.only(bottom: 90), children: [
              for (final a in rows)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [Expanded(child: Text(a['title'], style: const TextStyle(fontWeight: FontWeight.w700))), if (a['overdue'] == true) const StatusChip('OVERDUE')]),
                      const SizedBox(height: 4),
                      Text([a['requester'], if ((a['amount'] ?? 0) > 0) tk(a['amount']), a['since'], 'level ${a['level']}'].whereType<Object>().join(' · '), style: const TextStyle(color: Colors.grey)),
                      if (a['details'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${a['details']}')),
                      const SizedBox(height: 8),
                      Row(children: [
                        if (a['requisition_id'] != null) TextButton(onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => RequestDetail(id: a['requisition_id']))), child: const Text('Details')),
                        const Spacer(),
                        OutlinedButton(
                          onPressed: () async {
                            final reason = await askText(context, t('Reason'));
                            if (reason == null || reason.isEmpty || !context.mounted) return;
                            await run(context, () => Api.post('/approvals/${a['id']}', {'approve': false, 'comment': reason}));
                            await reload();
                          },
                          child: Text(t('Reject')),
                        ),
                        const SizedBox(width: 8),
                        FilledButton(
                          onPressed: () async {
                            await run(context, () => Api.post('/approvals/${a['id']}', {'approve': true}));
                            await reload();
                          },
                          child: Text(t('Approve')),
                        ),
                      ]),
                    ]),
                  ),
                ),
            ]),
    );
  }
}

class _ReqList extends StatelessWidget {
  final String box;
  const _ReqList({required this.box});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/requisitions', {'box': box}) as List,
      builder: (context, rows, reload) => rows.isEmpty
          ? ListView(children: const [EmptyNote('—')])
          : ListView(padding: const EdgeInsets.only(bottom: 90), children: [
              for (final r in rows)
                Card(
                  child: ListTile(
                    onTap: () async {
                      await Navigator.push(context, MaterialPageRoute(builder: (_) => RequestDetail(id: r['id'])));
                      await reload();
                    },
                    title: Text('${r['number']} · ${r['type_label']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${r['from']} → ${r['to']}\n${r['items']}', maxLines: 3, overflow: TextOverflow.ellipsis),
                    isThreeLine: true,
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [StatusChip(r['status']), if (r['priority'] == 'URGENT') const StatusChip('URGENT')]),
                  ),
                ),
            ]),
    );
  }
}

class StockList extends StatefulWidget {
  const StockList({super.key});
  @override
  State<StockList> createState() => _StockListState();
}

class _StockListState extends State<StockList> {
  String q = '';
  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/stock') as List,
      builder: (context, rows, _) => ListView(padding: const EdgeInsets.only(bottom: 90), children: [
        Padding(padding: const EdgeInsets.all(12), child: TextField(decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'search'), onChanged: (v) => setState(() => q = v.toLowerCase()))),
        for (final s in rows.where((s) => q.isEmpty || '${s['item']} ${s['code']}'.toLowerCase().contains(q)))
          ListTile(dense: true, title: Text(s['item']), subtitle: Text(s['code']), trailing: Text('${qty(s['qty'])} ${s['unit']}', style: TextStyle(fontWeight: FontWeight.w700, color: s['low'] == true ? Colors.red : null))),
      ]),
    );
  }
}

class NewRequestScreen extends StatefulWidget {
  const NewRequestScreen({super.key});
  @override
  State<NewRequestScreen> createState() => _NewRequestScreenState();
}

class _NewRequestScreenState extends State<NewRequestScreen> {
  Map? cfg;
  List items = [];
  String? type;
  int? branch;
  DateTime needed = DateTime.now().add(const Duration(days: 1)).copyWith(hour: 11, minute: 0);
  final lines = <Map<String, dynamic>>[];
  final notes = TextEditingController();
  bool urgent = false;

  @override
  void initState() {
    super.initState();
    Api.get('/requisition-types').then((c) async {
      final its = await Api.get('/items');
      if (!mounted) return;
      setState(() {
        cfg = c;
        items = its;
        final types = List.from(c['types']);
        type = types.isNotEmpty ? types.first['key'] : null;
      });
    }).catchError((e) {
      if (mounted) toast(context, '$e', error: true);
    });
  }

  Map? get typeCfg => cfg == null ? null : List.from(cfg!['types']).cast<Map?>().firstWhere((x) => x!['key'] == type, orElse: () => null);

  @override
  Widget build(BuildContext context) {
    final types = cfg == null ? [] : List.from(cfg!['types']);
    final tc = typeCfg;
    final allowed = tc == null ? [] : List.from(tc['item_types']);
    final pick = items.where((i) => allowed.contains(i['type'])).toList();
    final total = lines.fold<double>(0, (s, l) => s + ((num.tryParse('${l['qty']}') ?? 0) * (num.tryParse('${l['price'] ?? 0}') ?? 0)));
    return Scaffold(
      appBar: AppBar(title: Text(t('New request'))),
      body: cfg == null
          ? const Center(child: CircularProgressIndicator())
          : types.isEmpty
              ? const EmptyNote('You cannot raise requests. Ask your manager.')
              : ListView(padding: const EdgeInsets.all(16), children: [
                  DropdownButtonFormField<String>(
                    initialValue: type,
                    decoration: const InputDecoration(labelText: 'Type', border: OutlineInputBorder()),
                    items: [for (final x in types) DropdownMenuItem(value: x['key'] as String, child: Text(x['label']))],
                    onChanged: (v) => setState(() {
                      type = v;
                      lines.clear();
                    }),
                  ),
                  if (type == 'TRANSFER') ...[
                    const SizedBox(height: 10),
                    DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'From branch', border: OutlineInputBorder()), items: [for (final b in cfg!['branches']) DropdownMenuItem(value: b['id'] as int, child: Text(b['name']))], onChanged: (v) => branch = v),
                  ],
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.event),
                    title: Text('Needed by ${needed.day}/${needed.month} ${TimeOfDay.fromDateTime(needed).format(context)}'),
                    subtitle: type == 'FOOD_ORDER' ? Text('After ${cfg!['cutoff']} for tomorrow = URGENT') : null,
                    onTap: () async {
                      final d = await showDatePicker(context: context, initialDate: needed, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 60)));
                      if (d == null || !context.mounted) return;
                      final tm = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(needed));
                      if (tm != null) setState(() => needed = DateTime(d.year, d.month, d.day, tm.hour, tm.minute));
                    },
                  ),
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: urgent, onChanged: (v) => setState(() => urgent = v), title: const Text('Urgent')),
                  SectionTitle(t('Items')),
                  for (final (i, l) in lines.indexed)
                    Card(
                      margin: const EdgeInsets.symmetric(vertical: 4),
                      child: ListTile(
                        title: Text(l['name']),
                        subtitle: Text('${l['qty']} ${l['unit'] ?? ''}${l['price'] != null ? ' · ${tk((num.tryParse('${l['qty']}') ?? 0) * (num.tryParse('${l['price']}') ?? 0))}' : ''}'),
                        trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => setState(() => lines.removeAt(i))),
                      ),
                    ),
                  OutlinedButton.icon(
                    icon: const Icon(Icons.add),
                    label: Text(tc?['free_text'] == true ? 'Add problem' : 'Add item'),
                    onPressed: () async {
                      if (tc?['free_text'] == true) {
                        final d = await askText(context, 'Describe the problem');
                        if (d != null && d.isNotEmpty) setState(() => lines.add({'description': d, 'name': d, 'qty': 1}));
                        return;
                      }
                      final it = await showModalBottomSheet<Map>(context: context, isScrollControlled: true, builder: (_) => _ItemPicker(items: pick));
                      if (it == null || !context.mounted) return;
                      final q = await askText(context, '${it['name']} (${it['unit']})', hint: 'quantity', type: const TextInputType.numberWithOptions(decimal: true));
                      if (q == null || (num.tryParse(q) ?? 0) <= 0) return;
                      setState(() => lines.add({'item_id': it['id'], 'name': it['name'], 'unit': it['unit'], 'qty': q, 'price': it['last_price']}));
                    },
                  ),
                  const SizedBox(height: 8),
                  Text('Estimated ${tk(total)}', style: const TextStyle(color: Colors.grey)),
                  const SizedBox(height: 8),
                  TextField(controller: notes, decoration: InputDecoration(labelText: t('Note'), border: const OutlineInputBorder())),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: lines.isEmpty
                        ? null
                        : () async {
                            final r = await run(context, () => Api.post('/requisitions', {
                                  'type': type, 'needed_by': needed.toIso8601String(), 'priority': urgent ? 'URGENT' : 'NORMAL', 'notes': notes.text,
                                  if (branch != null) 'to_location_id': branch,
                                  'lines': [for (final l in lines) {if (l['item_id'] != null) 'item_id': l['item_id'], if (l['description'] != null) 'description': l['description'], 'qty': l['qty']}],
                                }));
                            if (r != null && context.mounted) Navigator.pop(context);
                          },
                    child: Text(t('Send')),
                  ),
                ]),
    );
  }
}

class _ItemPicker extends StatefulWidget {
  final List items;
  const _ItemPicker({required this.items});
  @override
  State<_ItemPicker> createState() => _ItemPickerState();
}

class _ItemPickerState extends State<_ItemPicker> {
  String q = '';
  @override
  Widget build(BuildContext context) {
    final list = widget.items.where((i) => q.isEmpty || '${i['name']} ${i['name_bn'] ?? ''} ${i['code']}'.toLowerCase().contains(q)).toList();
    return SizedBox(
      height: MediaQuery.of(context).size.height * .8,
      child: Column(children: [
        Padding(padding: const EdgeInsets.all(12), child: TextField(autofocus: true, decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'search item'), onChanged: (v) => setState(() => q = v.toLowerCase()))),
        Expanded(child: ListView(children: [for (final i in list) ListTile(title: Text(i['name']), subtitle: Text('${i['name_bn'] ?? ''} · ${i['unit']} · ${tk(i['last_price'])}'), onTap: () => Navigator.pop(context, i))])),
      ]),
    );
  }
}

class RequestDetail extends StatelessWidget {
  final int id;
  const RequestDetail({super.key, required this.id});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('Requests'))),
      body: Loader<Map>(
        load: () async => await Api.get('/requisitions/$id') as Map,
        builder: (context, r, reload) {
          final actions = List<String>.from(r['actions'] ?? []);
          Future<void> act(String a, [Map<String, dynamic> body = const {}]) async {
            await run(context, () => Api.post('/requisitions/$id/$a', body));
            await reload();
          }

          Future<void> qtyAction(String a, String field) async {
            final res = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, showDragHandle: true, builder: (_) => _QtySheet(r: r, action: a, field: field));
            if (res != null) await act(a, res);
          }

          return ListView(padding: const EdgeInsets.only(bottom: 30), children: [
            Card(
              margin: const EdgeInsets.all(12),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [Expanded(child: Text('${r['number']}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))), StatusChip(r['status'])]),
                  Text('${r['type_label']} · ${r['source']}', style: const TextStyle(color: Colors.grey)),
                  const Divider(),
                  infoRow(t('From'), '${r['from']}'),
                  infoRow(t('To'), '${r['to']}'),
                  infoRow('Needed by', '${r['needed_by']}'),
                  infoRow('By', '${r['requester']}'),
                  infoRow(t('Amount'), tk(r['amount'])),
                  if (r['notes'] != null) infoRow(t('Note'), '${r['notes']}'),
                  if (r['reject_reason'] != null) infoRow(t('Reason'), '${r['reject_reason']}'),
                ]),
              ),
            ),
            if (r['waiting_on'] != null)
              Card(color: Colors.blue.shade50, child: ListTile(leading: const Icon(Icons.hourglass_top), title: Text('${t('Waiting')}: ${r['waiting_on']}'))),
            SectionTitle(t('Items')),
            for (final l in r['lines'])
              ListTile(
                dense: true,
                title: Text('${l['name']}'),
                subtitle: Text('req ${qty(l['qty_requested'])}${l['qty_approved'] != null ? ' · ok ${qty(l['qty_approved'])}' : ''}${l['qty_sent'] != null ? ' · sent ${qty(l['qty_sent'])}' : ''}${l['qty_received'] != null ? ' · got ${qty(l['qty_received'])}' : ''}'),
                trailing: Text('${l['unit'] ?? ''}${l['stock_at_fulfiller'] != null && actions.contains('accept') ? '\nstock ${qty(l['stock_at_fulfiller'])}' : ''}', textAlign: TextAlign.right),
              ),
            if (actions.isNotEmpty)
              Padding(
                padding: const EdgeInsets.all(12),
                child: Wrap(spacing: 8, runSpacing: 8, children: [
                  if (actions.contains('submit')) FilledButton(onPressed: () => act('submit'), child: const Text('Submit')),
                  if (actions.contains('update-draft')) OutlinedButton(onPressed: () => qtyAction('update-draft', 'qty_requested'), child: const Text('Edit quantities')),
                  if (actions.contains('accept')) FilledButton(onPressed: () => qtyAction('accept', 'qty_requested'), child: Text(t('Accept'))),
                  if (actions.contains('dispatch')) FilledButton(onPressed: () => qtyAction('dispatch', 'qty_approved'), child: Text(t('Dispatch'))),
                  if (actions.contains('receive')) FilledButton(style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700), onPressed: () => qtyAction('receive', 'qty_sent'), child: Text(t('Receive'))),
                  if (actions.contains('shortage')) OutlinedButton.icon(onPressed: () => act('shortage'), icon: const Icon(Icons.shopping_cart), label: const Text('Purchase shortage')),
                  if (actions.contains('assign')) FilledButton.icon(onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => AssignBuyerScreen(reqId: id))).then((_) => reload()), icon: const Icon(Icons.person_add), label: const Text('Assign buyer')),
                  if (actions.contains('complete')) FilledButton(onPressed: () async {
                    final n = await askText(context, 'What was done?');
                    if (n != null) await act('complete', {'note': n});
                  }, child: const Text('Mark repaired')),
                  if (actions.contains('reject')) OutlinedButton(onPressed: () async {
                    final n = await askText(context, t('Reason'));
                    if (n != null && n.isNotEmpty) await act('reject', {'reason': n});
                  }, child: Text(t('Reject'))),
                  if (actions.contains('cancel')) TextButton(onPressed: () => act('cancel'), child: Text(t('Cancel'))),
                ]),
              ),
            if (r['purchase'] != null)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.shopping_cart, color: brand),
                  title: Text('${r['purchase']['number']} · ${r['purchase']['buyer']}'),
                  subtitle: Text('${r['purchase']['funding_label']} · due ${r['purchase']['deadline']}${r['purchase']['amount'] != null ? ' · ${tk(r['purchase']['amount'])}' : ''}'),
                  trailing: StatusChip(r['purchase']['status']),
                ),
              ),
            if ((r['children'] as List).isNotEmpty || (r['parents'] as List).isNotEmpty) ...[
              const SectionTitle('Linked'),
              for (final c in r['parents']) ListTile(dense: true, leading: const Icon(Icons.arrow_upward), title: Text('${c['number']} ${c['type_label']}'), trailing: StatusChip(c['status']), onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => RequestDetail(id: c['id'])))),
              for (final c in r['children']) ListTile(dense: true, leading: const Icon(Icons.arrow_downward), title: Text('${c['number']} ${c['type_label']}'), trailing: StatusChip(c['status']), onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => RequestDetail(id: c['id'])))),
            ],
            if ((r['approvals'] as List).isNotEmpty) ...[
              SectionTitle(t('Approvals')),
              for (final a in r['approvals']) ListTile(dense: true, title: Text('L${a['level']} · ${a['approver']}'), subtitle: Text('${a['by'] ?? ''} ${a['at'] ?? ''} ${a['comment'] ?? ''}'), trailing: StatusChip(a['status'])),
            ],
            SectionTitle(t('Timeline')),
            for (final h in r['history']) ListTile(dense: true, leading: const Icon(Icons.circle, size: 10, color: brand), title: Text('${h['status']}'.replaceAll('_', ' ')), subtitle: Text('${h['at']} · ${h['by'] ?? ''} ${h['note'] ?? ''}')),
          ]);
        },
      ),
    );
  }
}

class _QtySheet extends StatefulWidget {
  final Map r;
  final String action, field;
  const _QtySheet({required this.r, required this.action, required this.field});
  @override
  State<_QtySheet> createState() => _QtySheetState();
}

class _QtySheetState extends State<_QtySheet> {
  final ctrls = <int, TextEditingController>{};
  final note = TextEditingController();
  List drivers = [];
  int? driver;

  @override
  void initState() {
    super.initState();
    for (final l in widget.r['lines']) {
      var v = l[widget.field] ?? l['qty_approved'] ?? l['qty_requested'];
      if (widget.action == 'accept' && l['stock_at_fulfiller'] != null && widget.r['type'] != 'FOOD_ORDER') {
        final st = num.tryParse('${l['stock_at_fulfiller']}') ?? 0;
        final rq = num.tryParse('${l['qty_requested']}') ?? 0;
        v = st < rq ? (st < 0 ? 0 : st) : rq;
      }
      ctrls[l['id']] = TextEditingController(text: qty(v));
    }
    if (widget.action == 'dispatch') {
      Api.get('/drivers').then((d) => mounted ? setState(() => drivers = d) : null).catchError((_) => null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 0, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(widget.action.toUpperCase().replaceAll('-', ' '), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          for (final l in widget.r['lines'])
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(children: [
                Expanded(child: Text('${l['name']} (${l['unit'] ?? ''})')),
                SizedBox(width: 100, child: TextField(controller: ctrls[l['id']], keyboardType: const TextInputType.numberWithOptions(decimal: true), textAlign: TextAlign.right)),
              ]),
            ),
          if (widget.action == 'dispatch' && drivers.isNotEmpty)
            DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Driver (optional)'), items: [for (final d in drivers) DropdownMenuItem(value: d['id'] as int, child: Text(d['name']))], onChanged: (v) => driver = v),
          if (widget.action == 'receive' || widget.action == 'accept') TextField(controller: note, decoration: InputDecoration(labelText: t('Note'))),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: () => Navigator.pop(context, {
                'qtys': {for (final e in ctrls.entries) '${e.key}': num.tryParse(e.value.text) ?? 0},
                if (note.text.isNotEmpty) 'note': note.text,
                if (driver != null) 'driver_id': driver,
              }),
              child: const Text('Confirm'),
            ),
          ),
        ]),
      ),
    );
  }
}

class AssignBuyerScreen extends StatefulWidget {
  final int reqId;
  const AssignBuyerScreen({super.key, required this.reqId});
  @override
  State<AssignBuyerScreen> createState() => _AssignBuyerScreenState();
}

class _AssignBuyerScreenState extends State<AssignBuyerScreen> {
  int? buyer, method, vendor, petty;
  String? funding;
  int hours = 1;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Assign buyer')),
      body: Loader<Map>(
        load: () async => await Api.get('/purchase/options') as Map,
        builder: (context, o, _) {
          funding ??= List.from(o['fundings']).isNotEmpty ? o['fundings'][0]['key'] : null;
          return ListView(padding: const EdgeInsets.all(16), children: [
            DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Buyer', border: OutlineInputBorder()), items: [for (final b in o['buyers']) DropdownMenuItem(value: b['id'] as int, child: Text('${b['name']} · wallet ${tk(b['wallet'])}'))], onChanged: (v) => buyer = v),
            const SizedBox(height: 12),
            const Text('Deadline'),
            Wrap(spacing: 8, children: [for (final h in [1, 3, 6, 24]) ChoiceChip(label: Text(h < 24 ? '$h hour${h > 1 ? 's' : ''}' : '1 day'), selected: hours == h, onSelected: (_) => setState(() => hours = h))]),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(initialValue: funding, decoration: const InputDecoration(labelText: 'Funding', border: OutlineInputBorder()), items: [for (final f in o['fundings']) DropdownMenuItem(value: f['key'] as String, child: Text(f['label']))], onChanged: (v) => setState(() => funding = v)),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Payment method', border: OutlineInputBorder()), items: [for (final m in o['payment_methods']) DropdownMenuItem(value: m['id'] as int, child: Text(m['name']))], onChanged: (v) => method = v),
            if (funding == 'VENDOR_CREDIT') ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Vendor', border: OutlineInputBorder()), items: [for (final v in o['vendors']) DropdownMenuItem(value: v['id'] as int, child: Text('${v['name']} (${v['terms']})'))], onChanged: (v) => vendor = v),
            ],
            if (funding == 'PETTY_CASH') ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Petty cash fund', border: OutlineInputBorder()), items: [for (final p in o['petty_funds']) DropdownMenuItem(value: p['id'] as int, child: Text(p['name']))], onChanged: (v) => petty = v),
            ],
            const SizedBox(height: 20),
            FilledButton(
              onPressed: () async {
                if (buyer == null) return toast(context, 'Choose a buyer', error: true);
                final r = await run(context, () => Api.post('/purchase/requests/${widget.reqId}/assign', {
                      'buyer_id': buyer, 'funding': funding, 'deadline_at': DateTime.now().add(Duration(hours: hours)).toIso8601String(),
                      'payment_method_id': method, 'vendor_id': vendor, 'petty_account_id': petty,
                    }));
                if (r != null && context.mounted) Navigator.pop(context);
              },
              child: const Text('Assign & notify buyer'),
            ),
          ]);
        },
      ),
    );
  }
}
