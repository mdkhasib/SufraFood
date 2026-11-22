import 'package:material_ui/material_ui.dart';

import '../core/api.dart';
import '../core/i18n.dart';
import '../core/session.dart';
import '../core/ui.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final server = TextEditingController(text: Api.base);
  final login = TextEditingController();
  final pass = TextEditingController();
  bool busy = false;
  bool showServer = false;
  bool phoneMode = false;
  bool testing = false;
  String? testResult;
  bool testOk = false;
  Map<String, dynamic>? brandInfo;

  @override
  void initState() {
    super.initState();
    Api.branding().then((b) {
      if (mounted) setState(() => brandInfo = b);
    }).catchError((_) {});
  }

  Future<void> testServer() async {
    setState(() {
      testing = true;
      testResult = null;
      server.text = Api.normalize(server.text);
    });
    try {
      final b = await Api.branding(server.text);
      setState(() {
        testOk = true;
        brandInfo = b;
        testResult = '✓ ${t('Connected to')} ${b['name']}';
      });
    } on ApiException catch (e) {
      setState(() {
        testOk = false;
        testResult = e.status == 404 ? t('This address is not a GroupOps server.') : e.message;
      });
    } finally {
      if (mounted) setState(() => testing = false);
    }
  }

  static const demo = {
    'E-2008': 'Worker', 'E-2004': 'Team lead', 'E-2001': 'Branch mgr', 'E-1050': 'CK chef', 'E-1040': 'Warehouse',
    'E-1030': 'Purchase mgr', 'E-1031': 'Buyer', 'E-1060': 'Driver', 'E-1020': 'HR', 'E-1001': 'MD', 'E-1002': 'GM', 'SA-0001': 'Super admin',
  };

  Future<void> submit() async {
    setState(() => busy = true);
    try {
      await Session.I.login(server.text, login.text, pass.text);
    } on ApiException catch (e) {
      if (mounted) toast(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(24), children: [
          const SizedBox(height: 24),
          Center(
            child: brandInfo?['logo'] != null
                ? ClipRRect(
                    borderRadius: BorderRadius.circular(16),
                    child: Image.network(brandInfo!['logo'], height: 72, fit: BoxFit.contain, errorBuilder: (_, _, _) => _badge()),
                  )
                : _badge(),
          ),
          const SizedBox(height: 12),
          const Center(child: Text('Sufra GroupOps', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800))),
          if (brandInfo?['name'] != null) Center(child: Text(brandInfo!['name'], style: TextStyle(color: brand, fontWeight: FontWeight.w600))),
          const Center(child: Text('Restaurants · Kitchen · Catering · Warehouse', style: TextStyle(color: Colors.grey))),
          const SizedBox(height: 16),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            ChoiceChip(label: const Text('English'), selected: Session.I.lang == 'en', onSelected: (_) => Session.I.setLang('en', sync: false)),
            const SizedBox(width: 8),
            ChoiceChip(label: const Text('বাংলা'), selected: Session.I.lang == 'bn', onSelected: (_) => Session.I.setLang('bn', sync: false)),
          ]),
          const SizedBox(height: 20),
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: false, label: Text(t('Employee ID')), icon: const Icon(Icons.badge_outlined)),
              ButtonSegment(value: true, label: Text(t('Mobile number')), icon: const Icon(Icons.phone_android)),
            ],
            selected: {phoneMode},
            onSelectionChanged: (v) => setState(() {
              phoneMode = v.first;
              login.clear();
            }),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: login,
            keyboardType: phoneMode ? TextInputType.phone : TextInputType.text,
            textCapitalization: phoneMode ? TextCapitalization.none : TextCapitalization.characters,
            decoration: InputDecoration(
              labelText: phoneMode ? t('Mobile number') : t('Employee ID'),
              hintText: phoneMode ? '01700000031' : 'E-2008',
              border: const OutlineInputBorder(),
              prefixIcon: Icon(phoneMode ? Icons.phone_android : Icons.badge_outlined),
            ),
          ),
          const SizedBox(height: 12),
          TextField(controller: pass, obscureText: true, decoration: InputDecoration(labelText: t('Password'), border: const OutlineInputBorder(), prefixIcon: const Icon(Icons.lock_outline)), onSubmitted: (_) => submit()),
          const SizedBox(height: 16),
          SizedBox(
            height: 50,
            child: FilledButton(onPressed: busy ? null : submit, child: busy ? const CircularProgressIndicator(color: Colors.white) : Text(t('Sign in'), style: const TextStyle(fontSize: 16))),
          ),
          const SizedBox(height: 8),
          TextButton.icon(onPressed: () => setState(() => showServer = !showServer), icon: const Icon(Icons.dns_outlined, size: 18), label: Text('${t('Server address')}: ${server.text}')),
          if (showServer) ...[
            TextField(
              controller: server,
              keyboardType: TextInputType.url,
              autocorrect: false,
              decoration: InputDecoration(
                border: const OutlineInputBorder(),
                labelText: t('Server address'),
                hintText: 'http://192.168.110.205:8090',
                helperText: t('Example: http://192.168.110.205:8090 (office Wi-Fi) or https://ops.sufrafood.com'),
                helperMaxLines: 2,
              ),
              onChanged: (_) => setState(() => testResult = null),
            ),
            const SizedBox(height: 8),
            Row(children: [
              OutlinedButton.icon(
                onPressed: testing ? null : testServer,
                icon: testing ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.wifi_tethering, size: 18),
                label: Text(t('Test connection')),
              ),
              const SizedBox(width: 10),
              if (testResult != null) Expanded(child: Text(testResult!, style: TextStyle(color: testOk ? Colors.green.shade700 : Colors.red, fontSize: 13))),
            ]),
            const SizedBox(height: 4),
            Text(t('Ask your manager for the address, or open the web login page → “How to set up the app”.'), style: const TextStyle(color: Colors.grey, fontSize: 12)),
          ],
          const SizedBox(height: 16),
          const Text('Demo accounts (password 123456). Mobile login works too, e.g. branch manager 01700000031.', style: TextStyle(color: Colors.grey, fontSize: 12)),
          const SizedBox(height: 6),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final e in demo.entries)
              ActionChip(
                label: Text('${e.key} · ${e.value}', style: const TextStyle(fontSize: 12)),
                onPressed: () => setState(() {
                  phoneMode = false;
                  login.text = e.key;
                  pass.text = '123456';
                }),
              ),
          ]),
        ]),
      ),
    );
  }

  Widget _badge() => Container(
        width: 72, height: 72,
        decoration: BoxDecoration(color: brand, borderRadius: BorderRadius.circular(20)),
        alignment: Alignment.center,
        child: Text(brandInfo?['initials'] ?? 'সু', style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.bold)),
      );
}
