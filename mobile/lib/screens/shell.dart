import 'package:material_ui/material_ui.dart';

import '../core/i18n.dart';
import '../core/session.dart';
import 'attendance.dart';
import 'dashboard.dart';
import 'deliveries.dart';
import 'me.dart';
import 'purchases.dart';
import 'requests.dart';
import 'tasks.dart';
import 'team.dart';
import 'today.dart';

class NavTab {
  final String label;
  final IconData icon;
  final Widget Function() page;
  NavTab(this.label, this.icon, this.page);
}

/// Bottom navigation built only from what this person may use.
List<NavTab> tabsFor(Session s) {
  // ordered by importance; workers end up with Today · Tasks · Attendance · Me
  final tabs = <NavTab>[NavTab('Today', Icons.today, () => const TodayScreen())];
  if (s.can('reports.management')) tabs.add(NavTab('Dashboard', Icons.insights, () => const DashboardScreen()));
  final reqPerms = ['req.create.food_order', 'req.create.raw_material', 'req.create.supplies', 'req.create.equipment', 'req.create.repair', 'req.create.purchase', 'req.create.transfer', 'req.fulfil', 'req.receive', 'approvals.act', 'purchase.manage'];
  if (s.canAny(reqPerms) || s.groupWide) tabs.add(NavTab('Requests', Icons.inventory_2_outlined, () => const RequestsScreen()));
  if (s.canAny(['attendance.team_view', 'tasks.assign', 'teams.view'])) tabs.add(NavTab('Team', Icons.groups, () => const TeamScreen()));
  if (s.can('tasks.view_own')) tabs.add(NavTab('Tasks', Icons.checklist, () => const TasksScreen()));
  if (s.can('purchase.buy')) tabs.add(NavTab('Purchases', Icons.shopping_cart_outlined, () => const PurchasesScreen()));
  if (s.can('dispatch.drive')) tabs.add(NavTab('Deliveries', Icons.local_shipping_outlined, () => const DeliveriesScreen()));
  if (s.canAny(['attendance.self', 'shifts.view_own'])) tabs.add(NavTab('Attendance', Icons.access_time, () => const AttendanceScreen()));
  tabs.add(NavTab('Me', Icons.person_outline, () => const MeScreen()));
  return tabs;
}

class Shell extends StatefulWidget {
  const Shell({super.key});
  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  int index = 0;

  @override
  Widget build(BuildContext context) {
    var tabs = tabsFor(Session.I);
    List<NavTab> extra = [];
    if (tabs.length > 5) {
      // keep 4 + "More" so workers never see a crowded bar
      extra = tabs.sublist(4, tabs.length - 1);
      tabs = [...tabs.sublist(0, 4), NavTab('More', Icons.menu, () => MeScreen(extraTabs: extra))];
    }
    if (index >= tabs.length) index = 0;
    final current = tabs[index];
    return Scaffold(
      body: current.page(),
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (i) => setState(() => index = i),
        destinations: [for (final tb in tabs) NavigationDestination(icon: Icon(tb.icon), label: t(tb.label))],
      ),
    );
  }
}
