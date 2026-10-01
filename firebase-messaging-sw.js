importScripts('https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.22.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyD0RidVKjyRvYFd4ootXi5VWM28qVezwpo",
  projectId: "emotecaapp",
  storageBucket: "emotecaapp.firebasestorage.app",
  messagingSenderId: "955424631104",
  appId: "1:955424631104:web:d43214d2cd055b426eb2a5"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[firebase-messaging-sw.js] Messaggio in background ricevuto: ', payload);
  const notificationTitle = payload.notification.title;
  const notificationOptions = {
    body: payload.notification.body,
    icon: '/favicon.ico'
  };

  self.registration.showNotification(notificationTitle, notificationOptions);
});
