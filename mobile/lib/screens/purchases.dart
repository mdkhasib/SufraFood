import 'dart:convert';

import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/ui.dart';

class PurchasesScreen extends StatelessWidget {
  const PurchasesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(title: Text(t('Purchases')), bottom: TabBar(tabs: [Tab(text: t('Open')), Tab(text: t('Wallet')), Tab(text: t('Done'))])),
        body: const TabBarView(children: [_PurchaseList(state: 'open'), WalletView(), _PurchaseList(state: 'done')]),
      ),
    );
  }
}

class _PurchaseList extends StatelessWidget {
  final String state;
  const _PurchaseList({required this.state});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/purchases', {'state': state}) as List,
      builder: (context, rows, reload) => rows.isEmpty
          ? ListView(children: const [EmptyNote('—')])
          : ListView(children: [
              for (final p in rows)
                Card(
                  child: ListTile(
                    onTap: () async {
                      await Navigator.push(context, MaterialPageRoute(builder: (_) => PurchaseDetail(id: p['id'])));
                      await reload();
                    },
                    leading: Icon(p['overdue'] == true ? Icons.alarm : Icons.shopping_basket, color: p['overdue'] == true ? Colors.red : brand),
                    title: Text((p['lines'] as List).map((l) => '${l['name']} ${qty(l['qty'])}${l['unit'] ?? ''}').join(', '), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${p['number']} · ${p['funding_label']}\n⏱ ${p['deadline']} → ${p['deliver_to']}'),
                    isThreeLine: true,
                    trailing: StatusChip(p['status']),
                  ),
                ),
            ]),
    );
  }
}

class PurchaseDetail extends StatefulWidget {
  final int id;
  const PurchaseDetail({super.key, required this.id});
  @override
  State<PurchaseDetail> createState() => _PurchaseDetailState();
}

class _PurchaseDetailState extends State<PurchaseDetail> {
  final key = GlobalKey<LoaderState>();
  final qtyC = <int, TextEditingController>{};
  final priceC = <int, TextEditingController>{};
  final note = TextEditingController();
  String? photo;
  int? vendor;
  bool busy = false;

  Future<void> act(Future<dynamic> Function() f) async {
    setState(() => busy = true);
    await run(context, f);
    if (mounted) setState(() => busy = false);
    key.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(t('Purchases'))),
      body: Loader<Map>(
        key: key,
        load: () async => await Api.get('/purchases/${widget.id}') as Map,
        builder: (context, p, _) {
          for (final l in p['lines']) {
            qtyC.putIfAbsent(l['id'], () => TextEditingController(text: qty(l['qty'])));
            priceC.putIfAbsent(l['id'], () => TextEditingController(text: qty(l['est_price'])));
          }
          final st = p['status'];
          return ListView(padding: const EdgeInsets.all(12), children: [
            Card(
              margin: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [Expanded(child: Text(p['number'], style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))), StatusChip(st)]),
                  const Divider(),
                  infoRow(t('Due'), '${p['deadline']}${p['overdue'] == true ? '  ⚠ overdue' : ''}'),
                  infoRow('Funding', '${p['funding_label']}${p['payment_method'] != null ? ' · ${p['payment_method']}' : ''}'),
                  infoRow('Deliver to', '${p['deliver_to']}'),
                  if (p['vendor'] != null) infoRow('Vendor', '${p['vendor']}'),
                  if (p['amount'] != null) infoRow(t('Amount'), tk(p['amount'])),
                ]),
              ),
            ),
            const SizedBox(height: 12),
            if (st == 'ASSIGNED')
              FilledButton.icon(onPressed: busy ? null : () => act(() => Api.post('/purchases/${widget.id}/accept')), icon: const Icon(Icons.thumb_up), label: Text(t('Accept'))),
            if (st == 'ASSIGNED' || st == 'ACCEPTED') ...[
              SectionTitle(t('Submit receipt')),
              for (final l in p['lines'])
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Expanded(child: Text('${l['name']}\nlast ${tk(l['last_price'])}/${l['unit']}', style: const TextStyle(fontSize: 13))),
                    SizedBox(width: 70, child: TextField(controller: qtyC[l['id']], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'qty ${l['unit']}'))),
                    const SizedBox(width: 8),
                    SizedBox(width: 80, child: TextField(controller: priceC[l['id']], keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: '৳/unit'))),
                  ]),
                ),
              DropdownButtonFormField<int>(decoration: const InputDecoration(labelText: 'Vendor'), items: [for (final v in p['vendors']) DropdownMenuItem(value: v['id'] as int, child: Text(v['name']))], onChanged: (v) => vendor = v),
              TextField(controller: note, decoration: InputDecoration(labelText: t('Note'))),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () async {
                  final x = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 60, maxWidth: 1400);
                  if (x != null) setState(() => photo = x.path);
                },
                icon: Icon(photo == null ? Icons.receipt_long : Icons.check_circle, color: photo == null ? null : Colors.green),
                label: Text(photo == null ? '${t('Take photo')} (receipt)' : '✓ receipt photo ready'),
              ),
              const SizedBox(height: 8),
              FilledButton(
                onPressed: busy
                    ? null
                    : () => act(() {
                          final lines = {for (final l in p['lines']) '${l['id']}': {'qty': num.tryParse(qtyC[l['id']]!.text) ?? 0, 'unit_price': num.tryParse(priceC[l['id']]!.text) ?? 0}};
                          return Api.multipart('/purchases/${widget.id}/receipt', {'lines': jsonEncode(lines), 'note': note.text, if (vendor != null) 'vendor_id': '$vendor'}, filePath: photo);
                        }),
                child: Text(t('Submit receipt')),
              ),
            ],
            if (st == 'BOUGHT')
              FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700), onPressed: busy ? null : () => act(() => Api.post('/purchases/${widget.id}/delivered')), icon: const Icon(Icons.local_shipping), label: Text(t('Mark delivered'))),
            if (p['receipt_photo'] != null) Padding(padding: const EdgeInsets.only(top: 12), child: Image.network(p['receipt_photo'], height: 220, errorBuilder: (_, _, _) => const SizedBox())),
            if (p['price_flag'] == true) const Padding(padding: EdgeInsets.only(top: 8), child: Text('⚠ Price above last price — manager will check', style: TextStyle(color: Colors.red))),
          ]);
        },
      ),
    );
  }
}

class WalletView extends StatelessWidget {
  const WalletView({super.key});

  @override
  Widget build(BuildContext context) {
    return Loader<Map>(
      load: () async => await Api.get('/wallet') as Map,
      builder: (context, w, reload) => ListView(padding: const EdgeInsets.all(12), children: [
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          childAspectRatio: 1.9,
          mainAxisSpacing: 8,
          crossAxisSpacing: 8,
          children: [
            _box(t('Advance held'), tk(w['advance_held']), Colors.blueGrey),
            _box(t('Spent'), tk((w['spent'] ?? 0) + (w['spent_pending'] ?? 0)), Colors.orange),
            _box(t('Balance to return'), tk(w['balance']), brand),
            _box(t('Company owes me'), tk(w['company_owes']), Colors.purple),
          ],
        ),
        if ((w['receipts_pending'] ?? 0) > 0) Padding(padding: const EdgeInsets.only(top: 8), child: Text('${w['receipts_pending']} purchase(s) waiting goods-received', style: const TextStyle(color: Colors.orange))),
        const SizedBox(height: 8),
        OutlinedButton.icon(onPressed: () => _claim(context, reload), icon: const Icon(Icons.request_quote), label: Text(t('Claim refund'))),
        SectionTitle(t('Claim refund')),
        for (final c in w['claims']) ListTile(dense: true, title: Text('${c['description']}'), subtitle: Text('${c['at']}${c['paid_at'] != null ? ' · paid ${c['paid_at']}' : ''}'), trailing: Column(mainAxisAlignment: MainAxisAlignment.center, children: [Text(tk(c['amount']), style: const TextStyle(fontWeight: FontWeight.w700)), StatusChip(c['status'])])),
        const SectionTitle('History'),
        for (final x in w['transactions']) ListTile(dense: true, leading: StatusChip(x['type']), title: Text('${x['note'] ?? ''}'), subtitle: Text('${x['at']}'), trailing: Text(tk(x['amount']), style: const TextStyle(fontWeight: FontWeight.w700))),
      ]),
    );
  }

  Widget _box(String l, String v, Color c) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: c.withValues(alpha: .08), borderRadius: BorderRadius.circular(12)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [Text(l, style: const TextStyle(fontSize: 12)), Text(v, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: c))]),
      );

  Future<void> _claim(BuildContext context, Future<void> Function() reload) async {
    final amount = TextEditingController();
    final desc = TextEditingController();
    String? photo;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AlertDialog(
          title: Text(t('Claim refund')),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: '${t('Amount')} ৳')),
            TextField(controller: desc, decoration: const InputDecoration(labelText: 'What for')),
            TextButton.icon(onPressed: () async {
              final x = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 60);
              if (x != null) set(() => photo = x.path);
            }, icon: const Icon(Icons.photo_camera), label: Text(photo == null ? 'Receipt photo' : '✓ photo')),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(t('Cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t('Send')))],
        ),
      ),
    );
    if (ok == true && context.mounted) {
      await run(context, () => Api.multipart('/claims', {'amount': amount.text, 'description': desc.text}, filePath: photo));
      await reload();
    }
  }
}
