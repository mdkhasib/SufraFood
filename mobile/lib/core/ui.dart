import 'package:intl/intl.dart';
import 'package:material_ui/material_ui.dart';

import 'api.dart';
import 'i18n.dart';

const brand = Color(0xFFEA580C);

String tk(dynamic n) => '৳${NumberFormat('#,##0', 'en').format(num.tryParse('$n') ?? 0)}';
String qty(dynamic n) {
  final v = num.tryParse('$n') ?? 0;
  return v == v.roundToDouble() ? v.toInt().toString() : v.toStringAsFixed(2).replaceAll(RegExp(r'0+$'), '');
}

Color statusColor(String? s) {
  switch (s) {
    case 'DONE': case 'CLOSED': case 'APPROVED': case 'PAID': case 'PRESENT': case 'IN': case 'OUT': case 'SETTLED': case 'RECEIVED': case 'ACCEPTED': case 'DELIVERED': case 'CONFIRMED':
      return Colors.green.shade700;
    case 'REJECTED': case 'CANCELLED': case 'ABSENT': case 'NOT_ARRIVED': case 'OVERDUE':
      return Colors.red.shade700;
    case 'LATE': case 'PENDING': case 'PENDING_APPROVAL': case 'SUBMITTED': case 'PARTIALLY_ACCEPTED': case 'BOUGHT': case 'URGENT':
      return Colors.orange.shade800;
    case 'LEAVE': case 'HOLIDAY': case 'PROXY': case 'REMOTE':
      return Colors.purple.shade700;
    case 'OFF': case 'NO_SHIFT': case 'NOT_DUE': case 'DRAFT':
      return Colors.blueGrey;
    default:
      return Colors.blue.shade700;
  }
}

class StatusChip extends StatelessWidget {
  final String? status;
  const StatusChip(this.status, {super.key});
  @override
  Widget build(BuildContext context) {
    final c = statusColor(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
      child: Text((status ?? '').replaceAll('_', ' '), style: TextStyle(color: c, fontSize: 11, fontWeight: FontWeight.w700)),
    );
  }
}

void toast(BuildContext context, String msg, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(msg), backgroundColor: error ? Colors.red.shade700 : Colors.green.shade700, behavior: SnackBarBehavior.floating));
}

/// Runs an API action with a toast for the result.
Future<dynamic> run(BuildContext context, Future<dynamic> Function() f, {String? ok}) async {
  try {
    final r = await f();
    if (context.mounted) toast(context, ok ?? (r is Map && r['message'] != null ? r['message'].toString() : 'Done'));
    return r ?? true;
  } on ApiException catch (e) {
    if (context.mounted) toast(context, e.message, error: true);
  } catch (e) {
    if (context.mounted) toast(context, e.toString(), error: true);
  }
  return null;
}

Future<String?> askText(BuildContext context, String title, {String hint = '', String initial = '', TextInputType type = TextInputType.text}) {
  final c = TextEditingController(text: initial);
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: TextField(controller: c, autofocus: true, keyboardType: type, decoration: InputDecoration(hintText: hint)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: Text(t('Cancel'))),
        FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: const Text('OK')),
      ],
    ),
  );
}

/// Loads data with pull-to-refresh, loading and error states.
class Loader<T> extends StatefulWidget {
  final Future<T> Function() load;
  final Widget Function(BuildContext, T, Future<void> Function() reload) builder;
  const Loader({super.key, required this.load, required this.builder});
  @override
  State<Loader<T>> createState() => LoaderState<T>();
}

class LoaderState<T> extends State<Loader<T>> {
  T? data;
  String? error;
  bool loading = true;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    setState(() {
      loading = data == null;
      error = null;
    });
    try {
      final d = await widget.load();
      if (mounted) setState(() => data = d);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading && data == null) return const Center(child: CircularProgressIndicator());
    if (error != null && data == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.cloud_off, size: 48, color: Colors.grey),
            const SizedBox(height: 8),
            Text(error!, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            FilledButton(onPressed: reload, child: Text(t('Retry'))),
          ]),
        ),
      );
    }
    return RefreshIndicator(onRefresh: reload, child: widget.builder(context, data as T, reload));
  }
}

class SectionTitle extends StatelessWidget {
  final String text;
  final Widget? trailing;
  const SectionTitle(this.text, {super.key, this.trailing});
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 6),
        child: Row(children: [Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))), ?trailing]),
      );
}

class EmptyNote extends StatelessWidget {
  final String text;
  const EmptyNote(this.text, {super.key});
  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(text, style: const TextStyle(color: Colors.grey))));
}

Widget infoRow(String k, String v) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(width: 120, child: Text(k, style: const TextStyle(color: Colors.grey))),
        Expanded(child: Text(v, style: const TextStyle(fontWeight: FontWeight.w600))),
      ]),
    );
