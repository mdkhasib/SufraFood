import 'dart:math';

import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';

/// Logged-in user, permissions, enabled features and language.
class Session extends ChangeNotifier {
  static final Session I = Session._();
  Session._();

  Map<String, dynamic>? me;
  Set<String> perms = {};
  Set<String> features = {};
  String lang = 'en';
  bool ready = false;

  bool get loggedIn => me != null && Api.token != null;
  bool can(String p) => perms.contains(p);
  bool canAny(List<String> ps) => ps.any(perms.contains);
  bool on(String f) => features.contains(f);
  bool get groupWide => me?['group_wide'] == true;
  bool get hasTeam => me?['has_team'] == true;
  String get name => (lang == 'bn' && (me?['name_bn'] ?? '').toString().isNotEmpty) ? me!['name_bn'] : (me?['name'] ?? '');

  Future<void> boot() async {
    final p = await SharedPreferences.getInstance();
    Api.base = p.getString('server') ?? Api.base;
    Api.token = p.getString('token');
    lang = p.getString('lang') ?? 'en';
    Api.lang = lang;
    var dev = p.getString('device_id');
    if (dev == null) {
      final r = Random.secure();
      dev = List.generate(16, (_) => r.nextInt(16).toRadixString(16)).join();
      await p.setString('device_id', dev);
    }
    Api.deviceId = dev;
    Api.onUnauthorized = () => logout(local: true);
    if (Api.token != null) {
      try {
        _setMe(await Api.get('/me'));
      } catch (e) {
        if (e is ApiException && e.status == 401) {
          Api.token = null;
        }
      }
    }
    ready = true;
    notifyListeners();
  }

  void _setMe(dynamic m) {
    me = Map<String, dynamic>.from(m);
    perms = Set<String>.from(me!['permissions'] ?? []);
    features = Set<String>.from(me!['features'] ?? []);
  }

  Future<void> refreshMe() async {
    _setMe(await Api.get('/me'));
    notifyListeners();
  }

  Future<void> login(String server, String login, String password) async {
    Api.base = Api.normalize(server);
    final p = await SharedPreferences.getInstance();
    await p.setString('server', Api.base);
    final r = await Api.post('/login', {'login': login.trim(), 'password': password, 'device_id': Api.deviceId, 'device_name': 'android'});
    Api.token = r['token'];
    await p.setString('token', Api.token!);
    _setMe(r['user']);
    final serverLang = me!['language']?.toString();
    if (serverLang == 'bn' || serverLang == 'en') await setLang(serverLang!, sync: false);
    notifyListeners();
  }

  Future<void> logout({bool local = false}) async {
    if (!local) {
      try {
        await Api.post('/logout');
      } catch (_) {}
    }
    Api.token = null;
    me = null;
    perms = {};
    features = {};
    final p = await SharedPreferences.getInstance();
    await p.remove('token');
    notifyListeners();
  }

  Future<void> setLang(String l, {bool sync = true}) async {
    lang = l;
    Api.lang = l;
    final p = await SharedPreferences.getInstance();
    await p.setString('lang', l);
    notifyListeners();
    if (sync && loggedIn) {
      try {
        await Api.post('/me/language', {'language': l});
      } catch (_) {}
    }
  }
}
