# Network Setup Guide - Attendance Monitoring System
## Access from Other PCs to Central Server (20.20.52.75)

---

## PART 1: PREPARE THE MAIN SERVER PC (20.20.52.75)

### Step 1.1: Allow Windows Firewall Access
1. Press `Win + R` → Type `wf.msc` → Press Enter
2. Click "Inbound Rules" on left side
3. Click "New Rule..." on right side
4. Select "Port" → Click "Next"
5. Select "TCP" and enter Port: `8000` → Click "Next"
6. Select "Allow the connection" → Click "Next"
7. Check all three boxes (Domain, Private, Public) → Click "Next"
8. Name: "PHP Artisan Port 8000" → Click "Finish"

### Step 1.2: Start the Laravel Server
On the main PC, open PowerShell and run:
```powershell
cd C:\laragon\www\attendancemonitoring
php artisan serve --host=0.0.0.0 --port=8000
```
You should see: `Server running on [http://0.0.0.0:8000]`

### Step 1.3: Make MySQL Accessible Remotely
1. Open MySQL Workbench or phpMyAdmin
2. Create a user for remote access:
```sql
CREATE USER 'attendance_user'@'20.20.52.%' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON attendance_monitoring.* TO 'attendance_user'@'20.20.52.%';
FLUSH PRIVILEGES;
```

---

## PART 2: SETUP ON OTHER PCs (For Web Browser Access)

### Step 2.1: Test Browser Connection
1. On the other PC, open any browser (Chrome, Firefox, Edge)
2. Go to: `http://20.20.52.75:8000`
3. You should see the login page

### Step 2.2: Install Required Software
**Option A: If Using Fingerprint/Camera via USB**
1. Install drivers for your fingerprint scanner
2. Install drivers for your USB camera
3. Plug them in and test they work on that PC

**Option B: If Using ZKTeco Device**
1. Download ZKTeco software/drivers from manufacturer
2. Install and connect the device
3. Note down the device IP or COM port

### Step 2.3: Configure Browser for Face Recognition
The web app supports:
- Camera access for face registration
- RFID scanning via input simulation
- Fingerprint via API

When you login and navigate to "Employees" → "Facial Recognition", the browser will ask for camera permission. Click "Allow".

---

## PART 3: SETUP RFID READER ON OTHER PC

### Option A: USB RFID Reader (Recommended for Simplicity)

**Step 3A.1: Install RFID Reader**
1. Connect USB RFID reader to the other PC
2. Install drivers from manufacturer
3. Test: Open Notepad, hold RFID card near reader
4. It should print the RFID number

**Step 3A.2: Configure for Web App**
1. In browser, navigate to: `http://20.20.52.75:8000/admin`
2. Login with admin credentials
3. Go to Attendance section
4. Hover your RFID card over the reader
5. The RFID UID will appear in the system

---

### Option B: ZKTeco Device (Integrated Solution)

**Step 3B.1: Install ZKTecoBridge on Other PC**
1. Copy folder: `C:\laragon\www\attendancemonitoring\tools\ZKTecoBridge\`
   to the other PC (example: `C:\ZKTecoBridge\`)

2. Open `C:\ZKTecoBridge\bin\x86\Debug\ZKTecoBridge.exe.config`

3. Update these lines:
```xml
<add key="ApiBaseUrl" value="http://20.20.52.75:8000/api/zkteco" />
<add key="DeviceSerial" value="ZKTECO-PC2" />
<add key="LocalBridgeUrl" value="http://127.0.0.1:8765/" />
```

**Step 3B.2: Run ZKTecoBridge**
1. Double-click `C:\ZKTecoBridge\bin\x86\Debug\ZKTecoBridge.exe`
2. It should connect to the device and server
3. You should see connection logs

---

## PART 4: SETUP FINGERPRINT SCANNER ON OTHER PC

### Option A: USB Fingerprint Reader (ZKTeco or Third-party)

**Step 4A.1: Install Fingerprint Reader**
1. Connect fingerprint scanner via USB
2. Install drivers
3. Install the fingerprint software on that PC

**Step 4A.2: Test Fingerprint Scanner**
1. Run the fingerprint software
2. Enroll a test fingerprint
3. Verify it reads correctly

**Step 4A.3: Upload to Attendance System**
Via ZKTecoBridge or manual upload:
1. Login to web app: `http://20.20.52.75:8000/admin`
2. Go to Employees
3. Click on an employee
4. Navigate to "Fingerprint Management"
5. Follow the enrollment wizard

---

### Option B: ZKTeco Fingerprint (Built-in with Face/RFID)

**Step 4B.1: Enroll Fingerprint via ZKTecoBridge**
1. In browser, go to: `http://20.20.52.75:8000/admin/employees/{employee-id}`
2. Click "Enroll Fingerprint"
3. Follow the wizard
4. Place finger on the ZKTeco device on that PC
5. It uploads to central database

---

## PART 5: SETUP CAMERA ON OTHER PC

### Step 5.1: Install Camera
1. Connect USB camera to other PC
2. Install drivers (usually automatic)
3. Test: Open Windows Camera app, verify it works

### Step 5.2: Use Camera in Web App
1. Login to web app on other PC
2. Go to: Employees → Select employee → Facial Recognition
3. Click "Register Face"
4. Browser asks for camera permission → Click "Allow"
5. Position face in the oval guide
6. Click "Save" to upload face to central database

---

## PART 6: RECORD ATTENDANCE ON OTHER PC

### Step 6.1: Time In (With Any Method)

**Option 1: RFID Scanner**
1. Open browser: `http://20.20.52.75:8000/attendance`
2. Swipe RFID card
3. Click or voice command to record time in

**Option 2: Fingerprint Scanner**
1. Same web page
2. Select "Fingerprint" method
3. Place finger on scanner
4. System auto-records time in

**Option 3: Face Recognition**
1. Same web page
2. Select "Face" method
3. Look at camera
4. System recognizes and records time in

**Option 4: Manual (Keyboard)**
1. Same web page
2. Type employee ID manually
3. Click "Record Time In"

### Step 6.2: Time Out
- Same process at end of shift
- System will show time worked and duration

---

## PART 7: VERIFY DATA ON MAIN PC

### Step 7.1: Check Attendance Records
1. On main PC browser: `http://20.20.52.75:8000/admin`
2. Go to "Attendance" → View records
3. You should see entries from other PCs with:
   - Employee name
   - Time in/out
   - Method used (RFID, Fingerprint, Face)
   - Which PC recorded it (DeviceSerial)

### Step 7.2: Database Sync
All data from other PCs automatically syncs to the central MySQL database on 20.20.52.75:
- Attendance records ✓
- Employee faces ✓
- Fingerprint templates ✓
- Device logs ✓

---

## PART 8: TROUBLESHOOTING

### Issue: Other PC Can't Connect to Server
```
Solution:
1. Check if main PC firewall allows port 8000
2. Ping main PC: ping 20.20.52.75
3. Check if Laravel server is running on main PC
4. Verify correct IP address (not localhost)
```

### Issue: Camera Not Working
```
Solution:
1. Check Windows Camera works on that PC
2. Clear browser cache: Ctrl+Shift+Delete
3. Try different browser
4. Check browser has camera permission
```

### Issue: RFID Not Detected
```
Solution:
1. Test RFID reader with manufacturer software first
2. Check USB drivers installed
3. Make sure browser isn't blocking device access
4. Try simulating RFID input in Notepad to test
```

### Issue: Fingerprint Not Uploading
```
Solution:
1. Check ZKTecoBridge is running on that PC
2. Verify DeviceSerial is unique per PC
3. Check ApiBaseUrl points to correct server IP
4. Check network connectivity to server
```

### Issue: Database Not Syncing
```
Solution:
1. Check MySQL remote user was created
2. Test connection: mysql -h 20.20.52.75 -u attendance_user -p
3. Check firewall allows port 3306
4. Verify DB credentials in .env match
```

---

## QUICK REFERENCE: URLs TO USE ON OTHER PCs

| Function | URL | Purpose |
|----------|-----|---------|
| Web App | http://20.20.52.75:8000 | Login & Dashboard |
| Admin Panel | http://20.20.52.75:8000/admin | Manage employees |
| Attendance | http://20.20.52.75:8000/attendance | Record time in/out |
| Enroll Face | http://20.20.52.75:8000/admin/employees/{id} | Register faces |
| View Reports | http://20.20.52.75:8000/admin/reports | View attendance |

---

## NETWORK DIAGRAM

```
┌─────────────────────────────────────────────────┐
│         Main PC (20.20.52.75)                   │
│  - Laravel Server (Port 8000)                   │
│  - MySQL Database (Port 3306)                   │
│  - RFID Scanner (Optional)                      │
│  - Fingerprint Scanner (Optional)               │
│  - Camera (Optional)                            │
└─────────────────────────────────────────────────┘
                        ↓
            (Network: TCP/IP Port 8000)
                        ↓
        ┌───────────────┴───────────────┐
        ↓                               ↓
┌─────────────────┐           ┌─────────────────┐
│  Other PC #1    │           │  Other PC #2    │
│ - Browser       │           │ - Browser       │
│ - RFID Reader   │           │ - RFID Reader   │
│ - Fingerprint   │           │ - Fingerprint   │
│ - Camera        │           │ - Camera        │
│ - ZKTecoBridge  │           │ - ZKTecoBridge  │
└─────────────────┘           └─────────────────┘
```

---

## SECURITY NOTES

1. **Change Default Credentials**: Update admin password immediately
2. **Use Strong Passwords**: For MySQL and web app accounts
3. **Firewall**: Only allow port 8000 from authorized IPs if possible
4. **HTTPS**: Consider SSL certificate for production
5. **VPN**: If accessing over internet, use VPN tunnel

---

## FINAL CHECKLIST

- [ ] Main PC firewall allows port 8000
- [ ] Laravel server running on main PC
- [ ] MySQL remote user created
- [ ] Other PC can reach main PC via ping
- [ ] Browser can access http://20.20.52.75:8000
- [ ] ZKTecoBridge configured on other PC (if using)
- [ ] RFID reader connected and working
- [ ] Fingerprint scanner connected and working
- [ ] Camera connected and browser has permission
- [ ] Test attendance recorded from other PC
- [ ] Data visible on main PC admin panel

Once all checked, your multi-PC attendance system is ready! 🎉
