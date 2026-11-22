import 'package:material_ui/material_ui.dart';

import 'core/session.dart';
import 'core/ui.dart';
import 'screens/login.dart';
import 'screens/shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  Session.I.boot();
  runApp(const GroupOpsApp());
}

class GroupOpsApp extends StatelessWidget {
  const GroupOpsApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Session.I,
      builder: (context, _) => MaterialApp(
        title: 'Sufra GroupOps',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: brand, primary: brand),
          useMaterial3: true,
          scaffoldBackgroundColor: const Color(0xFFF7F7F5),
          cardTheme: const CardThemeData(color: Colors.white, elevation: 0, margin: EdgeInsets.symmetric(horizontal: 12, vertical: 5)),
          appBarTheme: const AppBarTheme(backgroundColor: Colors.white, surfaceTintColor: Colors.white, elevation: 0),
        ),
        home: !Session.I.ready
            ? const Scaffold(body: Center(child: CircularProgressIndicator()))
            : (Session.I.loggedIn ? Shell(key: ValueKey('${Session.I.me?['id']}-${Session.I.lang}')) : const LoginScreen()),
      ),
    );
  }
}
