# **Product Requirements Document: Leads/Contacts Page**

## **1\. Overview**

The Leads page (Contacts section) is a comprehensive lead management interface that displays a filterable, sortable list of contacts with engagement signals, AI-driven lead scoring, and detailed lead information. Users can view, filter, enrich, and qualify leads with an intuitive table layout and side-panel modal for individual lead details.

**Current URL:** `app.gojiberry.ai/contacts`

---

## **2\. Page Layout & Structure**

### **2.1 Main Content Area**

The page is divided into two primary sections:

* **Left Side:** Leads table with list view  
* **Right Side:** Lead detail modal (when a lead is selected)

### **2.2 Top Navigation**

* **Tab Navigation:** "All contacts" | "Lists" (switches between view modes)  
* **Search Bar:** Search by name, email, company, location, job title  
* **Additional Filters Button:** Opens/collapses the "Additional Filters" panel

---

## **3\. Filter & Sort Functionality**

### **3.1 Available Filters (Additional Filters Panel)**

| Filter | Type | Options |
| ----- | ----- | ----- |
| **AI Agent** | Dropdown | All Agents, My Agent |
| **List** | Dropdown | All Lists, Contacts without any list, My List (437) |
| **AI Score** | Dropdown | All Scores, First Signs of Interest 🔥, Actively Exploring 🔥🔥, Ready to Engage 🔥🔥🔥 |
| **Intent Type** | Dropdown | All Intent Types, Search Keyword (all/comment/like/post), Event Keyword, Group Keyword, Competitor Page URL, Influencer Page URL, Your Profile, Your Company, Recent Activity, Recently Changed Job, Recent Funding Event, Visited Profile |
| **Fit** | Dropdown | All Fits, Yes (qualified), Partly (unknown), No (out-of-scope), Not defined (null) |
| **Date Range** | Date Picker | Select date range... |

### **3.2 Sort Options**

| Sort Order |
| ----- |
| Default Order |
| Score: High to Low |
| Score: Low to High |

### **3.3 Filter Actions**

* **Refresh Counts Button:** Refreshes the count values in Intent Type dropdown  
* **Clear All Button:** Resets all filters to default state  
* **Close Button:** Collapses the Additional Filters panel

---

## **4\. Leads Table Structure**

### **4.1 Table Columns**

| Column | Description |
| ----- | ----- |
| **Checkbox** | Row selection for bulk actions |
| **Contact** | Lead name (clickable), title, company with LinkedIn badge if available |
| **Signal** | Engagement signal (e.g., "Just engaged with a LinkedIn post", keyword context) |
| **AI Score** | Visual score indicator (flame icons 🔥) |
| **Email** | Email enrichment status |
| **Import Date** | Date lead was imported (e.g., "4 hours ago") |
| **List** | List assignment (e.g., "My List") with checkmark indicator |
| **Fit** | Qualification status (✓, ?, X buttons) \- displayed as colored badges |

### **4.2 Row-Level Actions**

Each lead row includes:

* **Lead Name (Clickable):** Opens the Lead Detail Modal  
* **Enrich Button:** Enriches missing email data  
* **Qualification Buttons:** Three-state toggle  
  * ✓ (Green checkmark) \= Qualified/Yes  
  * ? (Yellow question mark) \= Unknown/Partly  
  * X (Red X) \= Not qualified/No  
* **Contact Now Button:** Quick action to initiate contact

### **4.3 Pagination & Display Options**

* **Results Display:** "Showing 1 to 100 of 502 results"  
* **Rows Per Page:** Dropdown menu (25, 50, 100, 200, all)  
* **Pagination Controls:** Previous/Next buttons \+ numbered page buttons (1, 2, 3, 4, 5, etc.)

---

## **5\. Top Toolbar Actions**

### **5.1 Bulk Action Buttons**

| Button | Function |
| ----- | ----- |
| **Add to list** | Add selected leads to a list |
| **Export to... / Export** | Export selected leads in various formats |
| **Enrich Email** (×2) | Bulk email enrichment action |
| **Add leads** | Add new leads to the system |

### **5.2 Additional Toolbar Controls**

* **Search functionality** maintained at top  
* **Add more filters** button to expand filter panel

---

## **6\. Lead Detail Modal (Side Panel)**

**Triggered by:** Clicking on a lead name in the table

### **6.1 Modal Header Section**

* **Profile Image:** Avatar/profile photo of the lead  
* **Lead Name:** Full name with LinkedIn badge/link  
* **Title & Company:** Job title and company name (e.g., "Founder & CEO @ JustFix")  
* **Find Email Button:** Quick action to find/enrich email address  
* **Close Button (X):** Closes the modal

### **6.2 Campaign Section**

* **Campaign Name Link:** (e.g., "My Campaign") \- clickable to view/edit campaign  
* **Campaign Delete Button:** Red trash icon to remove from campaign  
* **Message Journey Display:** Shows campaign stages with arrow flow (e.g., Invitation → Message → Message → Message)

### **6.3 Signal Section**

* **Section Title:** "Signal"  
* **Engagement Description:** Details the engagement trigger (e.g., "Just engaged with a LinkedIn post")  
* **Clickable Links:** Links to specific content that triggered the signal

### **6.4 Company Description Section**

* **Section Title:** "Company description"  
* **Description Text:** Brief company overview with "See more" expand button  
* **Truncation:** Shows first \~100 characters with ellipsis and "See more" link

### **6.5 AI Personalized Email Message Section**

* **Section Title:** "AI Personalized Email Message"  
* **Textbox:** Editable email message content  
* **Generate AI Message Button:** Creates context-aware email message (🪄 magic icon)  
* **Tooltip:** "Click to generate a context-aware AI message 🪄"  
* **Copy Email Body Button:** Copies message to clipboard

### **6.6 AI Personalized LinkedIn Message Section**

* **Section Title:** "AI Personalized LinkedIn Message"  
* **Helper Text:** "If AI Message is selected in a campaign, this message would be sent for this lead"  
* **Textbox:** Editable LinkedIn message content  
* **Generate AI Message Button:** Creates context-aware LinkedIn message  
* **Copy LinkedIn Message Button:** Copies message to clipboard  
* **Expand/Collapse Toggle:** Chevron to expand/collapse section

### **6.7 Basic Information Section**

| Field | Data Type | Editable |
| ----- | ----- | ----- |
| **Industry** | Text | No (read-only) |
| **Company Size** | Text | No (read-only) |
| **Company URL** | Hyperlink \+ Copy button | No |
| **Website** | Hyperlink \+ Copy button | No |
| **Location** | Text with icon | No (read-only) |

### **6.8 Notes Section**

* **Section Title:** "Notes"  
* **Profile Baseline:** Extracted from LinkedIn profile (read-only display)  
* **Profile Baseline Text:** Quote from profile headline or bio

### **6.9 Internal Notes Section**

* **Section Title:** "Internal Notes"  
* **Textbox:** Placeholder "Add your personal notes about this contact..."  
* **Save Button:** Persists internal notes (blue button)  
* **Purpose:** Store personal/internal observations about the lead

### **6.10 Activity Logs Section**

* **Section Title:** "Activity Logs"  
* **Collapsible:** Expand/collapse with chevron  
* **Content:** Displays activity history or "No activity logs yet"  
* **Note:** Currently shown as empty state

### **6.11 Modal Footer Section**

* **Delete Lead Button:** Red text button to permanently delete the lead  
* **Qualification Status Group:**  
  * ✓ (Green button) \= Qualified  
  * ? (Yellow button) \= Unknown/Unsure  
  * X (Red button) \= Not Qualified/Disqualified  
* **Creation Timestamp:** "Created on Feb 06, 2026 10:36 AM"  
* **Export Button:** Export this lead's data with dropdown menu

---

## **7\. Data Model & Fields**

### **7.1 Lead/Contact Data**

Lead {  
  id: string  
  firstName: string  
  lastName: string  
  email: string  
  title: string  
  company: string  
  companyUrl: string  
  website: string  
  industry: string  
  companySize: string  
  location: string  
  profileBaseline: string  
  linkedinProfile: string  
    
  // Engagement & Signal Data  
  signal: {  
    type: string (e.g., "LinkedIn post")  
    description: string  
    keyword: string  
    link: url  
    timestamp: datetime  
  }  
    
  // AI & Scoring  
  aiScore: enum \['exploratory', 'lead-to-follow', 'ready-to-contact'\]  
  fitStatus: enum \['qualified', 'unknown', 'out-of-scope', 'null'\]  
    
  // Campaign & Communication  
  campaignId: string  
  campaignName: string  
  emailMessage: string (AI-generated or custom)  
  linkedinMessage: string (AI-generated or custom)  
    
  // Internal  
  internalNotes: string  
  lists: string\[\] (list assignments)  
  importDate: datetime  
  createdDate: datetime  
    
  // Metadata  
  enrichmentStatus: {  
    email: boolean  
    profile: boolean  
  }

}

---

## **8\. User Actions & Workflows**

### **8.1 Viewing & Filtering Leads**

1. User lands on Leads page (shows all 502 leads by default, paginated)  
2. User applies filters (AI Agent, List, AI Score, Intent Type, Fit, Date Range)  
3. User sorts by AI Score (high to low, low to high, or default)  
4. Results update dynamically  
5. User can search by name, email, company

### **8.2 Viewing Lead Details**

1. User clicks on a lead name in the table  
2. Modal opens on the right side with full lead details  
3. Modal remains visible while user can still see table in background  
4. User can scroll within modal to see all sections  
5. User closes modal by clicking X button

### **8.3 Enriching Lead Data**

1. Click "Enrich" button on individual lead row, OR  
2. Select multiple rows and click "Enrich Email" from bulk toolbar  
3. System searches for missing email addresses  
4. Data is populated in the contact record

### **8.4 Qualifying Leads**

1. View lead in modal or identify in table  
2. Click one of three qualification buttons:  
   * ✓ \= Mark as Qualified  
   * ? \= Mark as Unknown/Unsure  
   * X \= Mark as Not Qualified  
3. Status persists and displays as colored badge in table "Fit" column

### **8.5 Adding Leads to Lists**

1. Click "Add to list" from bulk toolbar after selecting leads, OR  
2. Click list name in individual lead row  
3. Assign to predefined list (e.g., "My List")  
4. List assignment shows with checkmark indicator

### **8.6 Managing Campaign Messages**

1. Open lead modal  
2. Navigate to "AI Personalized Email Message" or "AI Personalized LinkedIn Message" sections  
3. Click "Generate AI Message" to auto-populate contextual message, OR  
4. Edit message text directly  
5. Click "Copy Email Body" or "Copy LinkedIn Message" to copy to clipboard  
6. Use message in campaign outreach

### **8.7 Exporting Leads**

1. Select leads from table (checkbox selection)  
2. Click "Export to... / Export" button  
3. Choose export format  
4. Download file with lead data, OR  
5. Click "Export" button on individual lead modal to export single lead

### **8.8 Adding Leads to Campaign**

1. Lead modal shows campaign assignment at top  
2. Click campaign name to view/edit campaign assignment  
3. Campaign stages display (Invitation → Message → Message → etc.)  
4. Red trash icon removes lead from campaign

### **8.9 Personal Notes & Documentation**

1. Open lead modal  
2. Scroll to "Internal Notes" section  
3. Click textbox and type personal observations  
4. Click "Save" button  
5. Notes persist in lead record

### **8.10 Deleting Leads**

1. Open lead modal  
2. Scroll to footer  
3. Click red "Delete lead" button  
4. Lead is permanently removed from system

---

## **9\. Key Features & Behaviors**

### **9.1 AI Scoring System**

* **Flame Icons:** 🔥 \= Single flame (exploratory), 🔥🔥 \= Two flames (lead-to-follow), 🔥🔥🔥 \= Three flames (ready-to-contact)  
* **Score Sorting:** Can sort leads by AI score to prioritize hottest prospects  
* **Refresh Counts:** Button updates intent type counts after filtering changes

### **9.2 Signal Intelligence**

* Tracks multiple signal types: Search Keywords (all, comment, like, post), Event Keywords, Competitor Pages, Influencers, Profile Visits, Company Page Visits, Job Changes, Funding Events, Recent Activity  
* Hyperlinked content references (e.g., "LinkedIn post", "Sales Navigator") take user to source

### **9.3 Email Enrichment**

* Missing emails marked with enrichment opportunity  
* Bulk enrichment available for multiple leads  
* Individual "Enrich" button on each row  
* "Find email" quick action in modal header

### **9.4 Smart Qualification**

* Three-state system allows nuanced lead assessment  
* Visual color coding (green=yes, yellow=maybe, red=no)  
* Quick access from both table and modal  
* Enables filtering and sorting by fit status

### **9.5 Campaign Integration**

* Leads linked to campaigns with visual journey display  
* AI-generated messages contextual to lead profile  
* Message editing before campaign send  
* Campaign removal via trash icon

### **9.6 Responsive Modal**

* Opens as right side panel without full page navigation  
* Scrollable content area for long profiles  
* All core actions accessible (qualify, delete, export, enrich)  
* Maintains table visibility for context

---

## **10\. Technical Specifications**

### **10.1 Pagination**

* Default: 100 results per page  
* Options: 25, 50, 100, 200, all (10,000)  
* Current display: "Showing 1 to 100 of 502 results"  
* Page navigation: Previous, numbered buttons (1-5 visible), Next

### **10.2 Performance Considerations**

* Lazy loading for modal content  
* Debounced search  
* Paginated results (502 total leads across 6 pages)  
* Filter counts refresh on demand

### **10.3 API Endpoints (Inferred)**

* `GET /contacts` \- List all leads with filters  
* `GET /contacts/{id}` \- Individual lead details  
* `POST /contacts` \- Create new lead  
* `PUT /contacts/{id}` \- Update lead details  
* `DELETE /contacts/{id}` \- Delete lead  
* `POST /contacts/enrich` \- Enrich email data  
* `POST /contacts/{id}/qualify` \- Update qualification status  
* `POST /contacts/export` \- Export leads  
* `POST /contacts/{id}/activity-logs` \- Fetch activity

---

## **11\. Error States & Edge Cases**

### **11.1 Empty States**

* "No activity logs yet" in Activity Logs section  
* Empty internal notes placeholder text

### **11.2 Loading States**

* Enrich button activity indicator during processing  
* AI message generation loading state  
* Filter count refresh indicator

### **11.3 Validation**

* Email enrichment only works if email field is empty  
* Qualification status must be one of three states (✓, ?, X)  
* List assignment from available list options only

---

## **12\. UI/UX Elements**

### **12.1 Visual Indicators**

* **LinkedIn Badges:** Blue badge icon next to names for LinkedIn profiles  
* **Flame Icons:** Colored flames for AI score (orange/red)  
* **Qualification Status:** Color-coded buttons (green ✓, yellow ?, red X)  
* **List Membership:** Green badge with checkmark

### **12.2 Interactive Elements**

* Clickable lead names (open modal)  
* Dropdown menus (filters)  
* Text inputs (search, internal notes)  
* Expandable sections (filters, modal sections)  
* Copy-to-clipboard buttons  
* Hyperlinks (company URLs, campaign names, signal sources)

### **12.3 Accessibility**

* Semantic HTML structure (form labels, heading hierarchy)  
* Button labels and ARIA attributes  
* Keyboard navigation support  
* Focus management in modal

---

## **13\. Metrics & Analytics**

### **13.1 Tracked Data**

* Lead engagement signals and types  
* AI score distribution  
* Qualification rate (% qualified, unknown, rejected)  
* Enrichment rate (email found %)  
* Campaign assignment and messaging  
* User filter patterns

---

## **14\. Future Considerations**

* Bulk qualification actions  
* Advanced scheduling for campaign messages  
* Lead scoring customization per user  
* Custom field support  
* Lead deduplication tools  
* Integration with CRM systems  
* Webhook support for signal triggers  
* API rate limiting considerations

