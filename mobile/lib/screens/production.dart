import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/ui.dart';

/// Central kitchen production orders for today (cooks & chefs).
class ProductionList extends StatelessWidget {
  const ProductionList({super.key});

  @override
  Widget build(BuildContext context) {
    return Loader<List>(
      load: () async => await Api.get('/production') as List,
      builder: (context, rows, reload) => rows.isEmpty
          ? ListView(children: [EmptyNote('${t('Production')}: —')])
          : ListView(children: [
              for (final p in rows)
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.soup_kitchen, color: brand),
                    title: Text('${p['item']}  ${qty(p['qty'])} ${p['unit']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${p['number']} · ${p['assigned_to'] ?? ''} ${p['completed_at'] != null ? '· done ${p['completed_at']}' : ''}'),
                    trailing: p['status'] == 'DONE'
                        ? const StatusChip('DONE')
                        : FilledButton.tonal(
                            onPressed: () async {
                              await run(context, () => Api.post('/production/${p['id']}/${p['status'] == 'PLANNED' ? 'start' : 'complete'}'));
                              await reload();
                            },
                            child: Text(p['status'] == 'PLANNED' ? t('Start') : t('Done')),
                          ),
                  ),
                ),
            ]),
    );
  }
}
