import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/ui.dart';

class DeliveriesScreen extends StatelessWidget {
  const DeliveriesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(title: Text(t('Deliveries')), bottom: TabBar(tabs: [Tab(text: t('Open')), Tab(text: t('Done'))])),
        body: const TabBarView(children: [_List(state: 'open'), _List(state: 'done')]),
      ),
    );
  }
}

class _List extends StatelessWidget {
  final String state;
  const _List({required this.state});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/deliveries', {'state': state}) as List,
      builder: (context, rows, reload) => rows.isEmpty
          ? ListView(children: const [EmptyNote('No deliveries 🚚')])
          : ListView(children: [
              for (final d in rows)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [Expanded(child: Text('${d['from']}  →  ${d['to']}', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800))), StatusChip(d['status'])]),
                      const SizedBox(height: 4),
                      Text('${d['number']} · ${d['requisition']} · ${d['vehicle'] ?? ''}', style: const TextStyle(color: Colors.grey)),
                      const SizedBox(height: 6),
                      Text('${d['items']}'),
                      if (d['picked_up_at'] != null) Text('${t('Picked up')} ${d['picked_up_at']}${d['delivered_at'] != null ? ' · ${t('Delivered')} ${d['delivered_at']}' : ''}', style: const TextStyle(color: Colors.grey)),
                      if (d['status'] == 'READY' || d['status'] == 'PICKED_UP')
                        Padding(
                          padding: const EdgeInsets.only(top: 10),
                          child: SizedBox(
                            width: double.infinity,
                            height: 48,
                            child: FilledButton(
                              style: FilledButton.styleFrom(backgroundColor: d['status'] == 'READY' ? brand : Colors.green.shade700),
                              onPressed: () async {
                                await run(context, () => Api.post('/deliveries/${d['id']}/${d['status'] == 'READY' ? 'pickup' : 'deliver'}'));
                                await reload();
                              },
                              child: Text(d['status'] == 'READY' ? t('Picked up') : t('Delivered')),
                            ),
                          ),
                        ),
                    ]),
                  ),
                ),
            ]),
    );
  }
}
