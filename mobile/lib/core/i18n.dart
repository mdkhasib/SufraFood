import 'session.dart';

const Map<String, String> _bn = {
  'Today': 'আজ', 'Tasks': 'কাজ', 'Attendance': 'হাজিরা', 'Team': 'টিম', 'Requests': 'রিকুইজিশন', 'Me': 'আমি', 'More': 'আরও',
  'Purchases': 'ক্রয়', 'Deliveries': 'ডেলিভারি', 'Dashboard': 'ড্যাশবোর্ড', 'Production': 'উৎপাদন', 'Approvals': 'অনুমোদন',
  'Sign in': 'লগইন', 'Employee ID or phone': 'কর্মী আইডি বা ফোন', 'Password': 'পাসওয়ার্ড', 'Server address': 'সার্ভারের ঠিকানা',
  'CHECK IN': 'চেক ইন', 'CHECK OUT': 'চেক আউট', 'Checked in': 'চেক ইন হয়েছে', 'Checked out': 'চেক আউট হয়েছে',
  'Your shift': 'আপনার শিফট', 'No shift today': 'আজ শিফট নেই', 'Weekly off': 'সাপ্তাহিক ছুটি', 'On leave today': 'আজ ছুটিতে',
  'My tasks today': 'আজকের কাজ', 'No tasks': 'কোনো কাজ নেই', 'Start': 'শুরু', 'Done': 'সম্পন্ন', 'Submit': 'জমা দিন',
  'Overdue': 'দেরি', 'Open': 'খোলা', 'Review': 'রিভিউ', 'Team tasks': 'টিমের কাজ', 'Assign task': 'কাজ দিন', 'Assign': 'দিন',
  'Take photo': 'ছবি তুলুন', 'Photo proof needed': 'ছবি প্রমাণ লাগবে', 'Note': 'নোট', 'Approve': 'অনুমোদন', 'Reject': 'বাতিল', 'Rework': 'আবার করুন',
  'Check in for my team': 'টিমের জন্য চেক ইন', 'Getting GPS location…': 'জিপিএস লোকেশন নেওয়া হচ্ছে…', 'Upcoming shifts': 'আসন্ন শিফট',
  'This month': 'এই মাস', 'Present': 'উপস্থিত', 'Late': 'দেরি', 'Absent': 'অনুপস্থিত', 'Leave': 'ছুটি', 'Off': 'ছুটি',
  'Request leave': 'ছুটির আবেদন', 'Correction': 'সংশোধন', 'Fix missed check-in': 'মিস হওয়া চেক-ইন ঠিক করুন',
  'Who is in': 'কে আছে', 'Proxy permissions': 'প্রক্সি অনুমতি', 'Language': 'ভাষা', 'Logout': 'লগআউট', 'Notifications': 'নোটিফিকেশন',
  'My payslips': 'আমার পে-স্লিপ', 'My KPI': 'আমার কেপিআই', 'Stock': 'স্টক', 'Salary advance': 'বেতন অগ্রিম', 'Save': 'সংরক্ষণ', 'Cancel': 'বাতিল',
  'New request': 'নতুন রিকুইজিশন', 'Mine': 'আমাদের', 'Incoming': 'আসা', 'Send': 'পাঠান', 'Accept': 'গ্রহণ', 'Dispatch': 'পাঠান', 'Receive': 'গ্রহণ নিশ্চিত',
  'Waiting': 'অপেক্ষা', 'Timeline': 'টাইমলাইন', 'Items': 'আইটেম', 'Wallet': 'ওয়ালেট', 'Advance held': 'অগ্রিম', 'Spent': 'খরচ',
  'Balance to return': 'ফেরত দিতে হবে', 'Company owes me': 'কোম্পানি দেবে', 'Submit receipt': 'রসিদ জমা দিন', 'Mark delivered': 'ডেলিভারি সম্পন্ন',
  'Picked up': 'তোলা হয়েছে', 'Delivered': 'পৌঁছে দেওয়া হয়েছে', 'Retry': 'আবার চেষ্টা', 'Score': 'স্কোর', 'Net pay': 'নিট বেতন',
  'Claim refund': 'রিফান্ড দাবি', 'Amount': 'পরিমাণ', 'Reason': 'কারণ', 'From': 'থেকে', 'To': 'পর্যন্ত', 'Due': 'সময়সীমা', 'Profile': 'প্রোফাইল',
  'Employee ID': 'কর্মী আইডি', 'Mobile number': 'মোবাইল নম্বর', 'Org chart': 'অর্গানোগ্রাম', 'Teams': 'টিম',
  'Priority': 'অগ্রাধিকার', 'Low': 'কম', 'Normal': 'সাধারণ', 'High': 'বেশি', 'Top priority': 'সর্বোচ্চ অগ্রাধিকার', 'Give it to': 'কাকে দেবেন',
  'People': 'লোকজন', 'Reports to you directly': 'সরাসরি আপনার অধীনে', 'Team leads & managers below you': 'আপনার নিচের টিম লিড ও ম্যানেজার',
  'Team lead shares it out': 'টিম লিড ভাগ করে দেবেন', 'Every member gets one': 'প্রত্যেক সদস্য পাবে', 'Reminders & alerts': 'রিমাইন্ডার ও নোটিফিকেশন',
  'Remind before due time': 'সময়ের আগে মনে করান', 'If late, remind again every': 'দেরি হলে আবার মনে করান প্রতি', 'Auto: by priority': 'অটো: অগ্রাধিকার অনুযায়ী',
  'Notify me when it is finished': 'শেষ হলে আমাকে জানান', 'Notify me when it is started': 'শুরু হলে আমাকে জানান', 'Remind now': 'এখনই মনে করান',
  'Assigned by me': 'আমার দেওয়া', 'Change': 'পরিবর্তন', 'No': 'না', 'Title': 'শিরোনাম', 'Details': 'বিস্তারিত', 'Lead': 'লিড', 'members': 'সদস্য',
  'Find a person or team': 'ব্যক্তি বা টিম খুঁজুন', 'Show only their team': 'শুধু তাদের টিম দেখুন', 'Choose a person or a team': 'একজন ব্যক্তি বা টিম বাছাই করুন',
  'Write a title': 'শিরোনাম লিখুন', 'Lines show who reports to whom. Tap a person to give them a task.': 'কে কার অধীনে তা দেখানো হয়েছে। কাজ দিতে নামের উপর চাপুন।',
  'Test connection': 'সংযোগ পরীক্ষা', 'Connected to': 'সংযুক্ত:', 'This address is not a GroupOps server.': 'এই ঠিকানাটি GroupOps সার্ভার নয়।',
  'Example: http://192.168.110.205:8090 (office Wi-Fi) or https://ops.sufrafood.com': 'উদাহরণ: http://192.168.110.205:8090 (অফিস Wi-Fi) অথবা https://ops.sufrafood.com',
  'Ask your manager for the address, or open the web login page → “How to set up the app”.': 'ঠিকানা ম্যানেজারের কাছে জেনে নিন, অথবা ওয়েব লগইন পেজে “How to set up the app” দেখুন।',
  'Good morning': 'শুভ সকাল', 'Hello': 'হ্যালো', 'pending approvals': 'অনুমোদন বাকি', 'Sales today': 'আজকের বিক্রি',
  // home delivery
  'Customer': 'কাস্টমার', 'Map': 'ম্যাপ', 'Cash collected': 'টাকা নেওয়া হয়েছে', 'Collect cash': 'টাকা নিতে হবে', 'Home delivery bonus': 'হোম ডেলিভারি বোনাস',
  'Food ready': 'খাবার তৈরি', 'Kitchen preparing': 'রান্না চলছে', 'Already paid · collect nothing': 'আগেই পরিশোধিত · টাকা নিতে হবে না',
  'Food is ready: collect it from the kitchen': 'খাবার তৈরি: কিচেন থেকে নিয়ে যান',
  'Kitchen is preparing it. You will get a message when it is ready.': 'রান্না চলছে। তৈরি হলে মেসেজ পাবেন।',
  'Picked up from kitchen': 'কিচেন থেকে নিয়েছি', 'Wait: kitchen is preparing': 'অপেক্ষা করুন: রান্না চলছে', 'Delivered to customer': 'কাস্টমারকে পৌঁছে দিয়েছি',
};

/// Translate a UI string to the current language (falls back to English).
String t(String key) => Session.I.lang == 'bn' ? (_bn[key] ?? key) : key;
