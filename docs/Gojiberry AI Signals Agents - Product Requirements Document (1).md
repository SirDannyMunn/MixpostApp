# **Gojiberry AI Signals Agents \- Product Requirements Document**

## **Executive Summary**

This PRD documents the complete Signals Agents feature within Gojiberry AI. Signals Agents are AI-powered lead generation bots that autonomously identify high-quality leads based on buying intent signals detected across LinkedIn. Users can create multiple agents with customized targeting criteria and intent signal configurations to automatically discover and manage leads.

---

## **1\. Product Overview**

**Feature Name:** Signals Agents  
 **Module:** Automated Lead Generation  
 **Purpose:** Enable users to set up autonomous AI agents that continuously monitor LinkedIn for leads matching specific criteria based on buying intent signals  
 **Target Users:** Sales teams, marketing teams, revenue operations professionals using Gojiberry AI  
 **Key Benefit:** Hands-off lead discovery that continuously finds prospects showing buying intent signals  
 **Maximum Agents Per Account:** 2 (displays as "2 max" in UI)  
 **Maximum Signals Per Agent:** 15 signals

---

## **2\. Main Signals Agents Page (`/ai-agents`)**

### **2.1 Page Layout & Components**

**Header Section:**

* Title: "Signals Agents"  
* Subtitle: "Manage your automated lead generation agents & signals"  
* Agent count display: "1 / 2 max" (shows current agents vs. maximum allowed)  
* "HOW IT WORKS?" button: Opens comprehensive help modal  
* Primary CTA: "+ Launch Agent" button (orange)

**Agent Table:**

| Column | Content | Type | Notes |
| ----- | ----- | ----- | ----- |
| Agent Name | Agent display name | Text with status badge | Status: "Active" (green badge) |
| Signals | Number of configured signals | Count | Shows "7 signals" for active agents |
| Actions | Agent management buttons | Button group | "Next launches", "Edit", "More options" (three-dot menu) |

**Additional UI Elements:**

* "See previous launches" expandable dropdown  
* Agents display in table format  
* Color coding: Agent name row highlighted when active

### **2.2 Agent Actions & Controls**

**Edit Button:**

* Navigates to `/ai-agents/edit/{agentId}`  
* Allows modification of all agent configuration settings  
* Route displays agent name in breadcrumb

**Next Launches Button:**

* Shows when the agent's next scheduled runs/launches are  
* Displays launch history and timing

**More Options (Three-dot Menu):**

* Triggers agent actions menu  
* Likely options: Pause/Resume, Delete, View details, etc.

**Launch Agent Button:**

* Primary CTA to create new agent  
* Maximum 2 agents allowed per account  
* Route likely: `/ai-agents/create` or similar flow

---

## **3\. "How It Works" Modal**

**Modal Title:** "How It Works"  
 **Subtitle:** "Your complete guide to using Gojiberry effectively"

### **3.1 The 3 Steps Section**

**Structure:** Sequential process overview  
 **Visual:** Orange box with numbered steps (1, 2, 3\)

**Step 1: Define your ICP**

* Description: "Who you want to sell to."  
* Sets foundation for targeting criteria  
* Job titles, company size, industry, location, company type, exclusions

**Step 2: Choose your signals**

* Description: "Where and how to track them."  
* Determines which buying intent indicators to monitor  
* Selects from 6 categories of intent signals

**Step 3: Build your list**

* Description: "Leads flow into a list you can use for outreach or export."  
* Configures lead destination and management  
* Lists appear in Leads Inbox for outreach setup

### **3.2 AI Agents Section**

**Heading:** "AI Agents" (with green checkmark)  
 **Tagline:** "AI Agents do the heavy lifting for you:"

**Key Capabilities:**

| Capability | Description | Detail |
| ----- | ----- | ----- |
| Runs 2 to 3 times per day | Frequency | Each agent checks 4 of your selected signals daily |
| Automatic collection | Data gathering | New leads matching your criteria are added directly to your Leads Inbox |
| Always fresh | Data freshness | You see only the latest activity, so you're reaching people at the right moment |

### **3.3 Intent Signals Section**

**Heading:** "Intent Signals"  
 **Intro:** "Your AI Agent monitors LinkedIn for the signals that matter most — so you only see leads showing real buying intent:"

**Signal Categories Listed:**

1. **Your Company** → People engaging with your team or page  
2. **Engagement & Interest** → Prospects interacting with relevant industry content  
3. **Experts & Creators** → Followers of trending thought leaders in your niche  
4. **Change & Trigger Events** → Job changes, new hires, or funding that signal new priorities  
5. **Community & Events** → Members of key groups or attendees at events  
6. **Competitor Engagement** → Prospects engaging directly with your competitors

**Additional Information:**

* "Pro Tip:" Choose the mix of signals that fits your Ideal Customer Profile, and your Agent will capture the right leads automatically.  
* "Maximum:" 15 signals per account. You can split them into different Agents.

### **3.4 ICP Filters Section**

**Heading:** "ICP Filters"  
 **Tagline:** "Stay focused on the right prospects:"

**Filter Capabilities:**

| Filter Type | Description |
| ----- | ----- |
| Define your ICP | Job titles, company size, industry, location, and more |
| Automatic filtering | Only leads matching your ICP criteria make it through |
| Exclusions | Remove unwanted profiles (wrong industries, students, competitors, etc.) with one click |

### **3.5 Lists & Exports Section**

**Heading:** "Lists & Exports"  
 **Tagline:** "Turn signals into pipeline:"

**Features:**

| Feature | Description |
| ----- | ----- |
| Lead lists | Organize leads by campaign, ICP, or priority |
| Export options | Send leads to your CRM, into an outreach campaign or download them anytime |
| Smart enrichment | Add verified emails/phones to maximize connect rates |

---

## **4\. Agent Edit Interface (`/ai-agents/edit/{agentId}`)**

### **4.1 Page Structure**

**Left Sidebar Navigation:**

* Step indicators (1, 2, 3\) with completion status  
* Step 1: ICP (Ideal Customer Profile) \- marked complete (green checkmark)  
* Step 2: Signals (Intent Signals) \- currently being edited (orange)  
* Step 3: Leads (Leads Management) \- available for configuration

**Main Content Area:**

* Agent name editable textbox  
* "HOW IT WORKS?" help button  
* "Update the agent configuration and targeting criteria" subtitle

**Footer:**

* "Previous" button (navigate backward)  
* "Save" button (orange) \- persists all changes

---

## **5\. Step 1: Define Your Ideal Customer Profile (ICP)**

**Purpose:** Configure targeting criteria for lead discovery

**Button:** "Generate ICP with AI" \- Auto-populates fields using company data

### **5.1 ICP Fields & Configuration**

#### **Target Job Titles**

| Attribute | Value |
| ----- | ----- |
| Type | Text input \+ Tags |
| Input field placeholder | "e.g., Sales Manager" |
| Add button | "Add" button to add custom titles |
| Default values | "Founder", "CEO", "Marketing Manager" |
| Display | Removable tags with X button |

#### **Target Locations**

| Attribute | Value |
| ----- | ----- |
| Type | Dropdown select |
| Default | "All locations" |
| Behavior | Multiple locations can be selected |
| Display | Shows selected location(s) |

#### **Target Industries**

| Attribute | Value |
| ----- | ----- |
| Type | Multi-select dropdown |
| Count display | "3 selected" indicator |
| Default selections | "Software Development & SaaS", "Marketing", "Retail & E-commerce" |
| Display | Removable tag pills with X button |
| Comprehensive list | 30+ industry options available |

#### **Company Types**

| Attribute | Value |
| ----- | ----- |
| Type | Multi-select dropdown |
| Count display | "2 selected" indicator |
| Default selections | "Private Company", "Startup" |
| Display | Removable tag pills |

#### **Company Sizes**

| Attribute | Value |
| ----- | ----- |
| Type | Multi-select dropdown |
| Count display | "3 selected" indicator |
| Default selections | "1-10 employees", "11-50 employees", "51-200 employees" |
| Options | Employee count ranges |
| Display | Removable tag pills |

#### **Companies & Keywords to Exclude**

| Attribute | Value |
| ----- | ----- |
| Type | Text input \+ Tags |
| Placeholder | "e.g., Google" |
| Add button | "Add" button to add exclusions |
| Default values | "Fiverr", "Upwork", "Wix", "Hootsuite", "ADT", "TechVision" |
| Display | Removable tag pills with X button |
| Purpose | Blacklist competitor or irrelevant companies |

#### **Lead Matching Mode**

| Attribute | Value |
| ----- | ----- |
| Type | Range slider |
| Range | 0-100 (labeled Discovery → High Precision) |
| Default | 100 (High Precision) |
| Left label | "Discovery" |
| Right label | "High Precision" |
| Indicator text | "Strict ICP \- Fewer, better leads. Only the strongest matches" |
| Info icon | Contextual help available |

#### **Advanced Filters**

| Attribute | Value |
| ----- | ----- |
| Type | Expandable link |
| Default | Collapsed/hidden |
| Trigger | Click "Advanced filters" link |
| Purpose | Additional filtering options |

#### **Additional Criteria (Optional)**

| Attribute | Value |
| ----- | ----- |
| Type | Text area |
| Placeholder | "Any additional criteria or specific requirements for your ideal customer. This will be sent to our AI Scoring system and used as a prompt to evaluate leads. e.g. specific sub-industries, cities to target, or exclusions to avoid" |
| Max length | 200 characters |
| Character counter | Shows "0/200" |
| Purpose | Free-form additional context for AI scoring |

#### **Mandatory Keywords**

| Attribute | Value |
| ----- | ----- |
| Type | Text input \+ Tags |
| Placeholder | "e.g., AI, Machine Learning, etc." |
| Add button | "Add" button |
| Purpose | Keywords that MUST appear in prospect profiles |
| Display | Removable tag pills |

#### **Exclude Service Providers Checkbox**

| Attribute | Value |
| ----- | ----- |
| Type | Checkbox |
| Label | "Exclude service providers, freelancers, and consultants" |
| Help text | "Filter out agencies, consultants, and B2B service companies from your results" |
| Default | Checked (on) |

#### **Skip ICP Filtering & Scoring Checkbox**

| Attribute | Value |
| ----- | ----- |
| Type | Checkbox |
| Label | "Skip ICP Filtering & Scoring" |
| Help text | "If checked, ICP filtering and scoring will be skipped for this agent, and all found leads will be added" |
| Default | Unchecked (off) |
| Use case | Bypass AI scoring for bulk lead collection |

#### **Include Open to Work Profiles Checkbox**

| Attribute | Value |
| ----- | ----- |
| Type | Checkbox |
| Label | "Include Open to Work Profiles" |
| Help text | "If not checked, profiles with 'Open to Work' status will be excluded from the results." |
| Default | Checked (on) |

---

## **6\. Step 2: Configure Intent Signals**

**Purpose:** Define what buying intent signals the AI agent should monitor  
 **URL:** Agent edit page (part of multi-step form)

**Signal Count Display:** "7 / 15 signals" (current / maximum)

**Subtitle:** "Define what signals the AI agent should look for to identify potential leads"

### **6.1 Intent Signal Categories**

#### **Signal Category 1: You & Your Company**

**Purpose:** Detect people engaging with your company or your team

**Sub-signals:**

1. **Your company LinkedIn Page**  
   * Input type: URL text field  
   * Placeholder: `https://linkedin.com/company/your-company`  
   * Validation: URL must start with `https://www.linkedin.com/company/`  
   * Help text: "This is useful only if your company page has published posts"  
   * Tracks: People viewing/engaging with company page  
2. **Your LinkedIn Profile**  
   * Input type: URL text field  
   * Placeholder: `https://linkedin.com/in/your-profile`  
   * Validation: URL must start with `https://www.linkedin.com/in/`  
   * Help text: "This is useful only if you have published posts on LinkedIn"  
   * Tracks: People viewing/engaging with personal profile  
3. **Visited Profile**  
   * Input type: Checkbox \+ Account dropdown  
   * Label: "Track your profile visitors"  
   * Account selector: Dropdown with "Second account" option  
   * Prerequisite: LinkedIn Premium or Sales Navigator  
   * Help text: "This signal requires LinkedIn Premium or Sales Navigator \- You must have connected your LinkedIn account to Gojiberry"  
   * Link: "your LinkedIn account to Gojiberry" (blue hyperlink to settings)  
4. **Your company Followers**  
   * Input type: URL input \+ Account dropdown  
   * Placeholder: `https://www.linkedin.com/company/107433042`  
   * Account selector: Dropdown with "Second account" option  
   * Prerequisite: Company page admin access  
   * Help text: "This signal requires selecting an account with administrator access to the LinkedIn company page \- You must have connected your LinkedIn account to Gojiberry"

#### **Signal Category 2: Engagement & Interest**

**Purpose:** Find people who recently engaged with relevant content on LinkedIn

**Configuration:**

* Count badge: "5" (indicates 5 keywords being tracked)  
* Description: "Track content mentioning keywords in your niche. Examples: 'Cold email', 'ISO27001', 'Hubspot CRM', etc."

**Sub-signals:**

1. **Keywords Research**  
   * Input type: Text input \+ Tags  
   * Button: "Generate with AI" (auto-populates keywords)  
   * Placeholder: "Enter a keyword..."  
   * Add button: "Add" button  
   * Display: Each keyword shows as tag with "Track:" radio button options  
   * Default keywords: "social media", "web design", "graphic design", "mobile apps", "CCTV"  
2. **Track Type (per keyword)**  
   * Input type: Radio button group  
   * Options:  
     * ○ Posts (user posted content)  
     * ○ Likes (user liked content)  
     * ○ Comments (user commented on content)  
     * ◉ All (track all interaction types) \- DEFAULT  
   * Behavior: One selection per keyword  
   * Display: Inline with keyword tag  
3. **Keyword Link**  
   * Clicking keyword tags links to LinkedIn search  
   * Format: `https://www.linkedin.com/search/results/all/?keywords="keyword"`  
   * Purpose: Verify keyword relevance

#### **Signal Category 3: LinkedIn Profiles**

**Purpose:** Spot people engaging with relevant LinkedIn profiles in your niche — in real time

**Configuration:**

* Description: "Track influencers, creators or any other LinkedIn profiles in your niche."  
* Help text: "Track influencers, creators or any other LinkedIn profiles in your niche."

**Sub-signals:**

1. **Profiles / Experts / Influencers**  
   * Input type: URL text input \+ Tags  
   * Placeholder: `https://linkedin.com/in/expert-profile`  
   * Validation: URL must start with `https://www.linkedin.com/in/`  
   * Add button: "Add" button  
   * Display: Added profiles show as tags  
   * Purpose: Track people following/engaging with thought leaders  
   * Max: Multiple profiles can be added

#### **Signal Category 4: Change & Trigger Events**

**Purpose:** Job changes, new hires, or funding announcements that suggest buying intent

**Configuration:**

* Count badge: "2" (indicates 2 trigger events selected)  
* Description: "Monitor organizational changes and trigger events that indicate opportunity."  
* Subheading: "Trigger events that suggests buying intent"

**Sub-signals (Checkboxes):**

1. **Track top 5% active profile in your ICP (Certainly high reply rate)**  
   * Input type: Checkbox  
   * Default: Checked ✓  
   * Help icon: Provides context  
   * Purpose: Identify most active prospects in target profile  
2. **Companies that have recently raised funds**  
   * Input type: Checkbox  
   * Default: Unchecked  
   * Help icon: Provides context  
   * Purpose: Target growth-focused companies  
3. **Recent job changes (\< 90 days)**  
   * Input type: Checkbox  
   * Default: Checked ✓  
   * Help icon: Provides context  
   * Purpose: Catch people in new roles (high receptiveness)

#### **Signal Category 5: Companies & Competitors Engagement**

**Purpose:** Track Leads following or interacting with competitors or other companies

**Configuration:**

* Description: "See who is engaging with other LinkedIn companies pages."

**Sub-signals:**

1. **Add a LinkedIn URL**  
   * Input type: URL text input \+ Tags  
   * Placeholder: `https://linkedin.com/company/competitor-name`  
   * Validation: URL must start with `https://www.linkedin.com/company/***`  
   * Add button: "Add" button  
   * Display: Added company pages show as tags  
   * Purpose: Track followers/engagers with competitor company pages  
   * Max: Multiple companies can be added

### **6.2 Signal Summary**

**Best Practices Panel (Sidebar):**

* Heading: "Best Practices"  
* Subtitle: "Improve your agent results"  
* Help text: "Follow these practices to help the AI agent find high-quality leads"

**Recommended Minimums:**

| Recommendation | Requirement |
| ----- | ----- |
| Competitors / Companies | Add at least 2 competitors or companies |
| Creators | Add at least 2 creators |
| Keywords | Add at least 2 keywords |
| Trigger Event | Add at least 1 trigger event |
| Total Signals | 5 signals minimum, 15 signals maximum |

**Signal Limits:**

* Minimum: 5 signals  
* Maximum: 15 signals per agent  
* Can split signals across multiple agents for more granular control

---

## **7\. Step 3: Leads Management**

**Purpose:** Configure how discovered leads are organized and stored

### **7.1 Leads Management Configuration**

**Page Title:** "Leads Management"  
 **Subtitle:** "Configure how leads will be organized and managed when found by the AI agent."

**Section 1: Automatically add found leads to list**

| Element | Details |
| ----- | ----- |
| Description | "Lists help you organize contacts and launch outreach campaigns more easily." |
| Type | Dropdown select |
| Label | "Select list" |
| Placeholder | "Select a list..." |
| Display | Shows list name and contact count (e.g., "My List (97 contacts)") |
| Default option | "My List (97 contacts)" |
| Secondary button | "+ Create new list" (orange button) |
| Purpose | Destination where discovered leads are automatically added |

**List Context Information:**

* Help text: "This list is not associated with a campaign. After creating the agent, you will be redirected to the creation of an outreach campaign"  
* Implies: Lists created here can be used for campaigns later  
* Workflow: Create agent → Create list → Set up outreach campaigns using leads

### **7.2 Lead Inbox Integration**

**Automatic Collection:**

* Found leads flow directly into selected list  
* List appears in "Leads Inbox" for review and campaign setup  
* Can be organized by campaign, ICP, or priority  
* Leads marked with discovery date/agent source

---

## **8\. Agent Lifecycle & Management**

### **8.1 Agent Status**

**Status Display:** Green "Active" badge  
 **Meaning:** Agent is currently running and monitoring signals  
 **Frequency:** Runs 2-3 times per day

### **8.2 Launch Schedule**

**Launch Timing:**

* Each agent checks 4 of selected signals daily  
* Multiple launches throughout the day (2-3 runs)  
* New leads added to list immediately upon discovery  
* "See previous launches" dropdown shows launch history

**Next Launches Button:**

* Shows upcoming scheduled runs  
* Displays execution timing  
* Shows last execution date/time

### **8.3 Agent Signal Updates**

**Signal Display:** "7 signals" badge

* Shows count of active signals configured  
* Updated whenever signals are modified  
* Affects lead discovery volume and relevance

---

## **9\. Data Requirements & API Contracts**

### **9.1 Agent Configuration Data Model**

json  
{  
  "agent": {  
    "id": "string (uuid)",  
    "name": "string",  
    "status": "enum (active | paused | inactive)",  
    "icp": {  
      "jobTitles": \["string"\],  
      "locations": \["string"\],  
      "industries": \["string"\],  
      "companyTypes": \["string"\],  
      "companySizes": \["string"\],  
      "excludedCompanies": \["string"\],  
      "excludeServiceProviders": "boolean",  
      "skipICPFiltering": "boolean",  
      "includeOpenToWork": "boolean",  
      "leadMatchingMode": "number (0-100)",  
      "additionalCriteria": "string (0-200 chars)",  
      "mandatoryKeywords": \["string"\]  
    },  
    "signals": {  
      "yourCompany": {  
        "companyPageUrl": "string (url)",  
        "personalProfileUrl": "string (url)",  
        "trackVisitedProfile": "boolean",  
        "trackCompanyFollowers": "boolean",  
        "account": "string (account identifier)"  
      },  
      "engagementInterest": {  
        "keywords": \[  
          {  
            "keyword": "string",  
            "trackType": "enum (posts | likes | comments | all)"  
          }  
        \]  
      },  
      "linkedinProfiles": {  
        "influencerProfiles": \["string (url)"\]  
      },  
      "changeAndTriggerEvents": {  
        "topActiveProfiles": "boolean",  
        "companiesFunding": "boolean",  
        "recentJobChanges": "boolean"  
      },  
      "companiesCompetitorEngagement": {  
        "companyPages": \["string (url)"\]  
      }  
    },  
    "leadsManagement": {  
      "destinationListId": "string (list id)",  
      "autoAddLeads": "boolean"  
    },  
    "launchSchedule": {  
      "frequency": "string (2-3 times daily)",  
      "signalsCheckedPerRun": "number (4)",  
      "lastLaunch": "timestamp",  
      "nextLaunch": "timestamp"  
    },  
    "createdAt": "timestamp",  
    "updatedAt": "timestamp",  
    "accountId": "string"  
  }  
}

### **9.2 Key API Endpoints**

| Endpoint | Method | Purpose | Input | Output |
| ----- | ----- | ----- | ----- | ----- |
| `/api/ai-agents` | GET | List user's agents | Account context | Array of agents |
| `/api/ai-agents` | POST | Create new agent | Agent configuration | Created agent object |
| `/api/ai-agents/{agentId}` | GET | Fetch agent details | Agent ID | Agent configuration |
| `/api/ai-agents/{agentId}` | PUT | Update agent config | Agent ID \+ updates | Updated agent |
| `/api/ai-agents/{agentId}` | DELETE | Delete agent | Agent ID | Confirmation |
| `/api/ai-agents/{agentId}/launch` | POST | Trigger manual agent run | Agent ID | Launch job ID |
| `/api/ai-agents/{agentId}/launches` | GET | Get launch history | Agent ID \+ pagination | Array of past launches |
| `/api/ai-agents/{agentId}/pause` | POST | Pause agent execution | Agent ID | Updated agent status |
| `/api/ai-agents/{agentId}/resume` | POST | Resume agent execution | Agent ID | Updated agent status |

---

## **10\. User Workflows & Scenarios**

### **10.1 Primary Workflow: Create and Launch Agent**

1. **User lands on Signals Agents page**  
   * Sees existing agents (if any)  
   * Limit: Max 2 agents per account  
   * Can see "Launch Agent" button  
2. **Click "Launch Agent"**  
   * Creates new agent  
   * Routes to agent edit page (Step 1: ICP)  
   * Agent auto-populated with company data (from account settings)  
3. **Configure ICP (Step 1\)**  
   * Review/edit job titles, industries, company sizes, locations  
   * Use "Generate ICP with AI" to auto-populate  
   * Set Lead Matching Mode slider (Discovery ↔ High Precision)  
   * Click "Next" → moves to Step 2: Signals  
4. **Configure Signals (Step 2\)**  
   * Select 5-15 intent signals  
   * Configure signal details (keywords, profiles, URLs, checkboxes)  
   * Review "Best Practices" recommendations  
   * Click "Next" → moves to Step 3: Leads Management  
5. **Configure Leads Management (Step 3\)**  
   * Select destination list (auto-creation enabled)  
   * Choose existing list or create new  
   * Click "Save"  
6. **Agent Created & Activated**  
   * Agent appears in agent table with status "Active"  
   * First launch begins immediately  
   * Leads flow into destination list within hours  
   * Agent continues running 2-3 times daily

### **10.2 Agent Modification Workflow**

1. **User views Signals Agents page**  
2. **Clicks "Edit" on agent**  
3. **Lands on agent edit page**  
   * Can navigate between steps (1, 2, 3\)  
   * Can edit any configuration field  
   * Step indicators show completion status  
4. **Make changes and click "Save"**  
   * Changes persist immediately  
   * Agent continues running with new configuration  
   * Next scheduled launch uses updated signals

### **10.3 Agent Pause/Resume Workflow**

1. **From Signals Agents page, click three-dot menu**  
2. **Select "Pause" option**  
   * Agent stops running  
   * Status changes from "Active" to "Paused"  
   * No new leads collected  
3. **To resume: Click menu → "Resume"**  
   * Agent resumes at next scheduled run

### **10.4 Agent Deletion Workflow**

1. **From Signals Agents page, click three-dot menu**  
2. **Select "Delete"**  
   * Confirmation dialog appears  
   * Agent removed from account  
   * Leads already collected remain in destination list  
3. **Agent slot now available**  
   * User can launch new agent (up to 2 max)

### **10.5 Review Signal Activity**

1. **Click "Next launches" button**  
   * Shows upcoming scheduled runs  
   * Displays when agent will run next  
2. **Expand "See previous launches"**  
   * Shows launch history  
   * Displays how many leads found in each run  
   * Shows execution timestamps

---

## **11\. Signal Categories Deep Dive**

### **11.1 "You & Your Company" Category**

**What it tracks:**

* People viewing your company LinkedIn page  
* People viewing your personal LinkedIn profile  
* Profile visitors (requires LinkedIn Premium)  
* Company page followers

**Requirements:**

* Company page URL (public page with posts)  
* Personal profile URL (if tracking personal profile engagement)  
* LinkedIn Premium or Sales Navigator (for profile visitors)  
* Company admin access (for followers tracking)

**Use Case:** Monitor direct audience engagement with your brand

### **11.2 "Engagement & Interest" Category**

**What it tracks:**

* People posting about keywords in your niche  
* People liking posts about keywords  
* People commenting on posts about keywords  
* All engagement types (selectable per keyword)

**Configuration:**

* Enter keywords relevant to your product/solution  
* Example keywords: "Cold email", "ISO27001", "Hubspot CRM", "Sales automation"  
* Track type selection: Posts, Likes, Comments, or All  
* Can generate keywords with AI

**Use Case:** Find prospects actively discussing pain points your solution solves

**Pro Tip:** More specific keywords \= higher quality leads but potentially lower volume

### **11.3 "LinkedIn Profiles" Category**

**What it tracks:**

* People following specific LinkedIn profiles  
* People engaging with specific profiles (views, comments, etc.)  
* Real-time engagement monitoring

**Configuration:**

* Add URLs of influencers/thought leaders in your niche  
* Examples: CEOs, industry analysts, popular content creators  
* System tracks who engages with their content

**Use Case:** Target engaged audiences of industry experts and thought leaders

**Requirements:** Profile must be accessible/public

### **11.4 "Change & Trigger Events" Category**

**What it tracks:**

1. **Top 5% Active Profiles**  
   * Most engaged users in your ICP  
   * Signal: High LinkedIn activity \= receptiveness  
   * Default: Enabled  
2. **Recent Job Changes (\< 90 days)**  
   * People who recently changed roles  
   * Signal: New role \= new priorities/budget  
   * Time window: Last 90 days  
   * Default: Enabled  
3. **Companies Raising Funds**  
   * Companies with recent funding announcements  
   * Signal: Growth \= hiring, new initiatives, budget available  
   * Default: Disabled (optional)

**Use Case:** Catch prospects at moment of highest buying intent

### **11.5 "Companies & Competitors Engagement" Category**

**What it tracks:**

* People following competitor company pages  
* People engaging with competitor pages  
* People interacting with company pages

**Configuration:**

* Add competitor company LinkedIn page URLs  
* Add strategic partner/adjacent company pages  
* System monitors followers and engagers

**Use Case:**

* Find prospects interested in competing solutions  
* Find prospects in adjacent markets

**Requirements:** Company page must be public

---

## **12\. Agent Execution & Operations**

### **12.1 Agent Execution Model**

**Frequency:** 2-3 times per day  
 **Signals checked per run:** 4 of selected signals  
 **Lead collection:** Automatic, added to destination list immediately  
 **Activity:** Always fresh \- only latest activity tracked

**Execution Logic:**

1. Agent wakes up on schedule  
2. Queries LinkedIn for 4 selected signals  
3. Evaluates candidates against ICP criteria  
4. Filters through lead matching mode (Discovery vs. High Precision)  
5. Adds matching leads to destination list  
6. Marks leads with agent source and discovery timestamp  
7. Returns to sleep until next scheduled run

### **12.2 Lead Quality Filtering**

**ICP Matching:**

* All discovered leads evaluated against ICP criteria  
* Automatic filtering applied based on job title, industry, location, company type, size  
* Exclusions applied (service providers, specific companies)  
* AI scoring system evaluates additional criteria and mandatory keywords

**Lead Matching Mode Impact:**

| Mode | Behavior | Lead Volume | Lead Quality |
| ----- | ----- | ----- | ----- |
| Discovery (0) | Loose criteria matching | High | Lower |
| Balanced (50) | Moderate matching | Medium | Medium |
| High Precision (100) | Strict criteria matching | Low | High |

### **12.3 Duplicate & Deduplication**

**Assumption:** System deduplicates leads across runs  
 **Behavior:** Same prospect won't appear in list multiple times  
 **Data persistence:** Once added, lead remains in list regardless of future runs

---

## **13\. Integration Points**

### **13.1 Campaign Integration**

**Workflow:** Agents → Leads Inbox → Campaign Creation

1. Leads discovered by agents land in destination list  
2. User navigates to create outreach campaign  
3. Selects destination list as lead source  
4. Campaign wizard uses discovered leads  
5. Outreach messages sent automatically per campaign workflow

**Link:** Lists Management page (Contacts module)

### **13.2 LinkedIn Integration**

**OAuth Required:**

* Direct LinkedIn connection for data access  
* Credentials never stored on Gojiberry servers  
* Used for LinkedIn API calls to fetch signal data

**Features requiring Premium/Sales Navigator:**

* Profile visitor tracking  
* Company follower tracking  
* Some job change signals

**Account Management:**

* Primary account for standard signals  
* Secondary accounts for admin-level signals (followers, visitor tracking)  
* Account selector dropdown in signal configuration

### **13.3 Leads Inbox Integration**

**Leads Inbox:** Central hub for discovered leads  
 **Flow:** Agent discovers lead → Automatically added to list → Appears in Leads Inbox  
 **Actions from Inbox:** Review, enrich, create campaigns, export

---

## **14\. Constraints & Limits**

| Constraint | Value | Notes |
| ----- | ----- | ----- |
| Max agents per account | 2 | Hard limit displayed in UI |
| Max signals per agent | 15 | Best practice: 5-15 range |
| Min signals per agent | 5 | Enforced minimum for effectiveness |
| Max job titles | Unlimited | Practical limit: 10-15 recommended |
| Max excluded companies | Unlimited | Display as tags |
| Launch frequency | 2-3x daily | Fixed, non-configurable |
| Lead matching mode range | 0-100 | Slider control, any value |
| Additional criteria length | 200 characters | Enforced in UI |
| Keyword tracking options | 4 types | Posts, Likes, Comments, All |

---

## **15\. Best Practices & Recommendations**

### **15.1 ICP Configuration Best Practices**

1. **Start specific:** Narrow ICP with strong filters first  
2. **Use AI generation:** Leverage "Generate ICP with AI" button  
3. **Avoid over-exclusion:** Only exclude truly irrelevant companies  
4. **Adjust match mode:** Start at High Precision, adjust to Discovery if needed  
5. **Leverage mandatory keywords:** Use for non-negotiable requirements

### **15.2 Signal Selection Best Practices**

1. **Balance signal types:** Mix company, engagement, and trigger signals  
2. **Minimum 5 signals:** Fewer signals \= less coverage  
3. **Target 7-10 signals:** Sweet spot for quality vs. volume  
4. **Review frequently:** Check signal relevance monthly  
5. **Keyword quality:** Use 2-5 relevant keywords, not generic terms  
6. **Add competitors:** Always monitor 1-2 main competitors  
7. **Follow influencers:** Add 1-2 key industry influencers  
8. **Trigger events:** Always enable at least job changes signal

### **15.3 Lead Management Best Practices**

1. **Organize lists:** Create separate lists by campaign or ICP segment  
2. **Regular review:** Check Leads Inbox daily for new discoveries  
3. **Create campaigns:** Convert leads to campaigns within 48 hours  
4. **Enrichment:** Use smart enrichment to add email/phone data  
5. **Export:** Download leads for CRM import when needed

---

## **16\. Error Handling & Validation**

| Validation | Error Message | Resolution |
| ----- | ----- | ----- |
| Duplicate agent name | "Agent name already exists" | Choose unique name |
| Invalid LinkedIn URL | "URL must start with [https://www.linkedin.com/](https://www.linkedin.com/)..." | Correct URL format |
| Missing required field | "\[Field name\] is required" | Complete required field |
| Too few signals | "Minimum 5 signals required" | Add more signals |
| Too many signals | "Maximum 15 signals allowed" | Remove signals or create new agent |
| LinkedIn connection failed | "Failed to connect LinkedIn account" | Re-authenticate LinkedIn |
| ICP generation failed | "Could not generate ICP. Try again." | Manual entry or try again later |

---

## **17\. Performance & Metrics**

### **17.1 Agent Performance Indicators**

| Metric | Display | Meaning |
| ----- | ----- | ----- |
| Signals count | "7 signals" | Number of active signals being monitored |
| Agent status | "Active" badge | Running and collecting leads |
| Launch frequency | "2-3x daily" | How often agent monitors signals |
| List size | "(97 contacts)" | Total leads in destination list |

### **17.2 Lead Quality Metrics**

**Accessible via Insights module:**

* Reply rate by agent  
* Campaign conversion rate by agent  
* Lead-to-customer rate by signal type  
* Most effective signals for ICP

---

## **18\. Future Roadmap Considerations**

1. **Agent A/B testing:** Run multiple signal configurations, compare results  
2. **Dynamic signal adjustment:** AI-recommended signal optimizations  
3. **Lead scoring:** AI-powered lead quality scoring per agent  
4. **Predictive triggers:** Advanced trigger events (funding, leadership changes)  
5. **Multi-account signals:** Track activity across multiple LinkedIn accounts  
6. **Signal templates:** Pre-built signal configurations by industry  
7. **Agent cloning:** Duplicate existing agents with modifications  
8. **Signal analytics:** Detailed view of signal performance and ROI  
9. **Custom signal creation:** User-defined signal types  
10. **Mobile agent management:** Pause/resume agents from mobile app

---

## **19\. Success Metrics & KPIs**

| KPI | Target | Calculation |
| ----- | ----- | ----- |
| Agents created per user | 80%+ | Users creating at least 1 agent |
| Average signals per agent | 7-10 | Mean signal count across agents |
| Lead discovery rate | 50+ leads/week | Per active agent |
| ICP match rate | 85%+ | Discovered leads matching ICP |
| Campaign creation rate | 70%+ | Users creating campaigns from agent leads |
| Lead-to-customer rate | Varies | By signal type and ICP quality |
| Agent engagement | 3+ monthly checks | Users reviewing agent status |

---

## **20\. Appendix: Sample Agent Configuration**

### **Sample Data Used in Documentation**

| Field | Sample Value |
| ----- | ----- |
| Agent Name | My Agent |
| Status | Active |
| Target Job Titles | Founder, CEO, Marketing Manager |
| Target Industries | Software Development & SaaS, Marketing, Retail & E-commerce |
| Target Company Sizes | 1-10 employees, 11-50 employees, 51-200 employees |
| Target Locations | All locations |
| Company Types | Private Company, Startup |
| Excluded Companies | Fiverr, Upwork, Wix, Hootsuite, ADT, TechVision |
| Lead Matching Mode | 100 (High Precision) |
| Engagement Keywords | "social media", "web design", "graphic design", "mobile apps", "CCTV" |
| Keyword Track Type | All interactions |
| Trigger Events Enabled | Top 5% active profiles (✓), Recent job changes (✓), Companies raising funds (✗) |
| Signals Configured | 7 of 15 maximum |
| Destination List | My List (97 contacts) |
| Launch Frequency | 2-3 times per day |

---

**Document Version:** 1.0  
 **Last Updated:** October 2, 2026  
 **Status:** Complete Specification  
 **Owner:** Product Management

## **How It Works**

Your complete guide to using Gojiberry effectively

### **The 3 Steps**

Every setup follows the same simple flow:

**1**  
Define your ICP → Who you want to sell to.

**2**

Choose your signals → Where and how to track them.

**3**

Build your list → Leads flow into a list you can use for outreach or export.

### **AI Agents**

AI Agents do the heavy lifting for you:

* **1**  
* Runs 2 to 3 times per day: Each agent checks 4 of your selected signals daily.  
* **2**  
* Automatic collection: New leads matching your criteria are added directly to your Leads Inbox.  
* **3**  
* Always fresh: You see only the latest activity, so you're reaching people at the right moment.

### **ICP Filters**

Stay focused on the right prospects:

* Define your ICP: Job titles, company size, industry, location, and more.  
* Automatic filtering: Only leads matching your ICP criteria make it through.  
* Exclusions: Remove unwanted profiles (wrong industries, students, competitors, etc.) with one click.

### **Intent Signals**

Your AI Agent monitors LinkedIn for the signals that matter most — so you only see leads showing real buying intent:

* Your Company → People engaging with your team or page.  
* Engagement & Interest → Prospects interacting with relevant industry content.  
* Experts & Creators 🪄 → Followers of trending thought leaders in your niche.  
* Change & Trigger Events → Job changes, new hires, or funding that signal new priorities.  
* Community & Events → Members of key groups or attendees at events.  
* Competitor Engagement → Prospects engaging directly with your competitors.

Pro Tip: Choose the mix of signals that fits your Ideal Customer Profile, and your Agent will capture the right leads automatically.

Maximum: 15 signals per account. You can split them into different Agents.

### **Lists & Exports**

Turn signals into pipeline:

* Lead lists: Organize leads by campaign, ICP, or priority.  
* Export options: Send leads to your CRM, into an outreach campaign or download them anytime.  
* Smart enrichment: Add verified emails/phones to maximize

