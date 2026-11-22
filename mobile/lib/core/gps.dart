import 'package:geolocator/geolocator.dart';

import 'api.dart';

/// Current GPS fix as API fields: lat, lng, accuracy, mocked.
Future<Map<String, dynamic>> gpsFix() async {
  if (!await Geolocator.isLocationServiceEnabled()) {
    throw ApiException('Turn on Location (GPS) on your phone.');
  }
  var perm = await Geolocator.checkPermission();
  if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
  if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) {
    throw ApiException('Allow location permission for this app to check in.');
  }
  final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 25)));
  return {'lat': p.latitude, 'lng': p.longitude, 'accuracy': p.accuracy, 'mocked': p.isMocked};
}
