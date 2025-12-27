# 🧪 Quick Test Guide - Database Persistence

## Test the Fix Right Now

### **1. Check Current State**

```sql
-- Before testing, check if record exists
SELECT * FROM professionals WHERE user_id = 57;

-- If exists, note current values
-- If not exists, perfect for testing!
```

### **2. Fill the Form**

1. Login as User ID 57
2. Go to: `http://localhost:9000/complete-profile/professional`
3. Fill in:
   - Business Name: **VET MAIS VET 1**
   - CNPJ: **98.305.809/0001-26**
   - Website: **WWW.VETMAISVET.COM.BR**
4. Wait 2 seconds (auto-save triggers)

### **3. Check Response**

Open Developer Tools → Network → Find the save request:

**Expected Response**:
```json
{
  "success": true,
  "message": "Rascunho salvo com sucesso",
  "saved_at": "2025-12-07T03:30:28+00:00",
  "saved_to_database": true  ← THIS IS NEW!
}
```

### **4. Verify Database**

```sql
-- Check professionals table
SELECT 
    id,
    user_id,
    business_name,
    cnpj,
    website,
    professional_type,
    created_at,
    updated_at
FROM professionals 
WHERE user_id = 57;
```

**Expected Result**:
```
id: [some number]
user_id: 57
business_name: VET MAIS VET 1
cnpj: 98.305.809/0001-26
website: WWW.VETMAISVET.COM.BR
professional_type: clinic (or whatever user type is)
created_at: [timestamp]
updated_at: [timestamp] ← Should be recent!
```

### **5. Test Progressive Save**

1. Click "Próximo Passo" (go to step 2)
2. Fill additional fields:
   - Opening Hours: **08:00**
   - Closing Hours: **18:00**
   - Description: **Best vet clinic**
3. Wait 2 seconds

**Check Database Again**:
```sql
SELECT 
    business_name,
    opening_hours,
    closing_hours,
    description
FROM professionals 
WHERE user_id = 57;
```

**Expected**:
- ✅ business_name still there: "VET MAIS VET 1"
- ✅ opening_hours added: "08:00"
- ✅ closing_hours added: "18:00"
- ✅ description added: "Best vet clinic"

---

## ✅ Success Criteria

- [x] Response has `saved_to_database: true`
- [x] Database has professional record
- [x] Fields match form input
- [x] Progressive saves work (step 1 + step 2)
- [x] Existing fields not overwritten
- [x] Empty fields don't erase data

---

## 🐛 If It Doesn't Work

### Check Laravel Logs:
```bash
cd 2pets-api
tail -f storage/logs/laravel.log
```

Look for:
- "Professional draft saved for user 57"
- Any error messages

### Check Database Connection:
```bash
php artisan tinker

# In tinker:
DB::table('professionals')->where('user_id', 57)->first();
```

### Common Issues:

1. **Professional table doesn't exist**:
   ```bash
   php artisan migrate
   ```

2. **User ID wrong**:
   - Check actual logged-in user
   ```sql
   SELECT id, email, user_type FROM users WHERE email = 'your@email.com';
   ```

3. **Permissions**:
   - Check database user has INSERT/UPDATE rights

---

## 📊 Quick Verification Query

```sql
-- Complete check for User 57
SELECT 
    u.id as user_id,
    u.name as user_name,
    u.email,
    u.user_type,
    p.id as professional_id,
    p.business_name,
    p.cnpj,
    p.website,
    p.opening_hours,
    p.closing_hours,
    p.updated_at as last_updated
FROM users u
LEFT JOIN professionals p ON p.user_id = u.id
WHERE u.id = 57;
```

**This shows everything in one query!**

---

## 🎉 Expected Final State

After testing completely:

**Cache**:
- ✅ Has draft with all fields

**Database - professionals table**:
- ✅ Has record for user_id 57
- ✅ business_name populated
- ✅ cnpj populated
- ✅ website populated
- ✅ hours populated
- ✅ description populated

**Database - users table**:
- ✅ Address fields updated (if filled)
- ✅ phone updated (if filled)

**User Experience**:
- ✅ Can refresh page → data restored
- ✅ Can close browser → data persists
- ✅ Can login from different device → data available

---

**Status**: Ready to test!  
**Expected Result**: ✅ Data persists in database  
**Time to Test**: 2 minutes

