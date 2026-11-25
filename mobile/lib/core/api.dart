import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

class ApiException implements Exception {
  final String message;
  final int status;
  ApiException(this.message, [this.status = 0]);
  @override
  String toString() => message;
}

/// Thin JSON client for the Laravel API.
class Api {
  /// Production server; build with --dart-define=SERVER_URL=http://192.168.x.x:8090 for an office test server.
  static String base = const String.fromEnvironment('SERVER_URL', defaultValue: 'https://erp.sufrabd.com');
  static String? token;
  static String lang = 'en';
  static String deviceId = '';
  static void Function()? onUnauthorized;

  static Map<String, String> _headers({bool json = true}) => {
        'Accept': 'application/json',
        if (json) 'Content-Type': 'application/json',
        'X-Lang': lang,
        'X-Device-Id': deviceId,
        if (token != null) 'Authorization': 'Bearer $token',
      };

  /// Cleans what people type: adds http://, drops spaces, a trailing slash, "/api" or "/login".
  static String normalize(String s) {
    var v = s.trim().replaceAll(' ', '');
    if (v.isEmpty) return base;
    if (!v.startsWith('http://') && !v.startsWith('https://')) {
      // a domain (erp.sufrabd.com) is https; an office IP / localhost is plain http
      final host = v.split('/').first.split(':').first;
      final local = host == 'localhost' || RegExp(r'^[0-9.]+$').hasMatch(host);
      v = local ? 'http://$v' : 'https://$v';
    }
    while (v.endsWith('/')) {
      v = v.substring(0, v.length - 1);
    }
    for (final tail in ['/api', '/login', '/app']) {
      if (v.endsWith(tail)) v = v.substring(0, v.length - tail.length);
    }
    return v;
  }

  /// Public company info (name + logo); also used to test the server address.
  static Future<Map<String, dynamic>> branding([String? server]) async {
    final old = base;
    if (server != null) base = normalize(server);
    try {
      final r = await _guard(() async => _handle(await http.get(_uri('/branding'), headers: _headers()).timeout(const Duration(seconds: 8))));
      return Map<String, dynamic>.from(r as Map);
    } finally {
      if (server != null) base = old;
    }
  }

  static Uri _uri(String path, [Map<String, String>? q]) {
    final b = base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    return Uri.parse('$b/api$path').replace(queryParameters: (q == null || q.isEmpty) ? null : q);
  }

  static dynamic _handle(http.Response r) {
    dynamic body;
    try {
      body = r.body.isEmpty ? null : jsonDecode(utf8.decode(r.bodyBytes));
    } catch (_) {
      body = null;
    }
    if (r.statusCode >= 200 && r.statusCode < 300) return body;
    if (r.statusCode == 401) {
      onUnauthorized?.call();
      throw ApiException('Session expired. Please log in again.', 401);
    }
    String msg = 'Error ${r.statusCode}';
    if (body is Map && body['message'] != null) msg = body['message'].toString();
    if (body is Map && body['errors'] is Map) {
      final errs = (body['errors'] as Map).values.expand((e) => e is List ? e : [e]).join('\n');
      if (errs.isNotEmpty) msg = errs;
    }
    throw ApiException(msg, r.statusCode);
  }

  static Future<T> _guard<T>(Future<T> Function() f) async {
    try {
      return await f().timeout(const Duration(seconds: 25));
    } on SocketException {
      throw ApiException('Cannot reach the server ($base). Check Wi-Fi / server address.');
    } on TimeoutException {
      throw ApiException('Server is not responding. Try again.');
    } on http.ClientException catch (e) {
      throw ApiException('Network error: ${e.message}');
    }
  }

  static Future<dynamic> get(String path, [Map<String, String>? q]) =>
      _guard(() async => _handle(await http.get(_uri(path, q), headers: _headers())));

  static Future<dynamic> post(String path, [Map<String, dynamic>? body]) =>
      _guard(() async => _handle(await http.post(_uri(path), headers: _headers(), body: jsonEncode(body ?? {}))));

  static Future<dynamic> delete(String path) =>
      _guard(() async => _handle(await http.delete(_uri(path), headers: _headers())));

  static Future<dynamic> multipart(String path, Map<String, String> fields, {String? filePath, String field = 'photo'}) =>
      _guard(() async {
        final req = http.MultipartRequest('POST', _uri(path))
          ..headers.addAll(_headers(json: false))
          ..fields.addAll(fields);
        if (filePath != null) req.files.add(await http.MultipartFile.fromPath(field, filePath));
        final streamed = await req.send();
        return _handle(await http.Response.fromStream(streamed));
      });
}
