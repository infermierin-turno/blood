// File: blood/sw.js
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyD0RidVKjyRvYFd4ootXi5VWM28qVezwpo",
  projectId: "emotecaapp",
  messagingSenderId: "955424631104",
  appId: "1:955424631104:web:d43214d2cd055b426eb2a5"
});

const messaging = firebase.messaging();

// Gestione della ricezione della notifica in background
messaging.onBackgroundMessage((payload) => {
  console.log('[sw.js] Ricevuta notifica in background: ', payload);
  const notificationTitle = payload.notification.title;
  const notificationOptions = {
    body: payload.notification.body,
    icon: '/assets/icon.png' // Opzionale: metti l'icona della tua app se ce l'hai
  };

  self.registration.showNotification(notificationTitle, notificationOptions);
});
