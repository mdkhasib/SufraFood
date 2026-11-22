import 'package:flutter_test/flutter_test.dart';
import 'package:groupops/core/session.dart';
import 'package:groupops/screens/login.dart';
import 'package:groupops/screens/shell.dart';
import 'package:material_ui/material_ui.dart';

void main() {
  testWidgets('login screen shows demo accounts and server field', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));
    expect(find.text('Sufra GroupOps'), findsOneWidget);
    expect(find.textContaining('E-2008'), findsOneWidget);
    await tester.tap(find.textContaining('E-2008'));
    await tester.pump();
    expect(find.text('E-2008'), findsOneWidget); // filled into the login field
  });

  test('worker gets only Today, Tasks, Attendance, Me', () {
    final s = Session.I;
    s.perms = {'tasks.view_own', 'shifts.view_own', 'attendance.self', 'leave.request', 'kpi.view_own'};
    s.me = {'group_wide': false};
    expect(tabsFor(s).map((t) => t.label).toList(), ['Today', 'Tasks', 'Attendance', 'Me']);
  });

  test('branch manager gets team, requests and dashboard; bar is capped with More', () {
    final s = Session.I;
    s.perms = {'tasks.view_own', 'tasks.assign', 'attendance.self', 'attendance.team_view', 'req.create.food_order', 'reports.management'};
    s.me = {'group_wide': false};
    final labels = tabsFor(s).map((t) => t.label).toList();
    expect(labels, containsAll(['Today', 'Dashboard', 'Team', 'Requests', 'Me']));
    expect(labels.contains('Purchases'), isFalse);
  });

  test('buyer and driver get their own tab', () {
    final s = Session.I;
    s.me = {'group_wide': false};
    s.perms = {'attendance.self', 'purchase.buy'};
    expect(tabsFor(s).map((t) => t.label), contains('Purchases'));
    s.perms = {'attendance.self', 'dispatch.drive'};
    expect(tabsFor(s).map((t) => t.label), contains('Deliveries'));
  });
}
